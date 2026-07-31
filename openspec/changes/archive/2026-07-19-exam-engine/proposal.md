# Proposal: Exam Engine (Student Taking, Strict Timer, Auto-Grading)

## Intent (Why)

Changes 1–5 built the teacher side and the student auth/subscription foundation. Students can register, log in, join a class, and see a dashboard — but they cannot take an exam. PRD §4.1 defines the exam submission flow with a strict server-side timer and the "X / Y" instant score; PRD §5.8 (`student_attempts`) and §5.9 (`student_answers`) persist the attempt. This change delivers the full first product slice per decision #1: the attempt/answer data model, the strict on-submit auto-grading engine for SINGLE and MULTIPLE questions (PRD §4.1 strict rule), the server-enforced countdown timer, the one-question-at-a-time wizard UI, and a dashboard section listing available and completed exams. No deferred concern is silently included.

## What Changes

### Data model (PRD §5.8 / §5.9)
- New migration `*_create_student_attempts_table.php`: `id`, `student_id` FK→`users.id` `onDelete('cascade')`, `exam_id` FK→`exams.id` `onDelete('cascade')`, `score_obtained` `DECIMAL(8,2)` nullable, `started_at` timestamp, `finished_at` timestamp nullable, timestamps; `unique(['student_id','exam_id'])` enforces 1 attempt per student per exam.
- New migration `*_create_student_answers_table.php`: `id`, `student_attempt_id` FK→`student_attempts.id` `onDelete('cascade')`, `question_id` FK→`questions.id` `onDelete('cascade')`, `answer_option_id` FK→`answer_options.id` `onDelete('cascade')`, timestamps; `unique(['student_attempt_id','question_id','answer_option_id'])`. **The UNIQUE is on 3 columns, not 2** — this is the resolved shape that allows MULTIPLE questions to record several selected options (option (c) of the open schema question), one row per selected option.
- New Eloquent models `app/Models/StudentAttempt.php` and `app/Models/StudentAnswer.php` with `#[Fillable]` attributes, modern cast helpers, and relationships (`StudentAttempt::belongsTo(User,'student_id')`, `belongsTo(Exam)`, `hasMany(StudentAnswer)`; `StudentAnswer::belongsTo(StudentAttempt)`, `belongsTo(Question)`, `belongsTo(AnswerOption,'answer_option_id')`). `Exam::attempts()`, `Question::answers()`, `AnswerOption::answers()`, `User::attempts()` added additively.

### Grading engine (strict MCQ rule, PRD §4.1)
- New `app/Services/ExamGradingService.php` with `gradeAttempt(StudentAttempt $attempt): float`. Iterates the exam's questions; for each question loads the student's `student_answers` rows and applies: SINGLE — exactly one selected option and it MUST be `is_correct` to award `question.points`, else 0; MULTIPLE — every selected option MUST be `is_correct` AND every option with `is_correct` MUST be selected AND no `is_correct=false` option selected → award `question.points`, else 0. Returns the sum. Idempotent: re-grading an already-graded attempt yields the same number.

### Student exam controller + actions
- New `app/Http/Controllers/Student/ExamController.php`:
  - `start(Exam)` — abort 403 if an attempt already exists for `student_id`+`exam_id`; require `role:STUDENT` + active `class_user` subscription to the exam's class (else 403); create `StudentAttempt` (`started_at=now()`) and redirect to `take`.
  - `show(StudentAttempt)` — render the current question of the wizard; compute the remaining seconds server-side from `started_at + exam.duration_minutes`; if expired, auto-submit and redirect to `result`.
  - `answer(StudentAttempt, Question)` — `updateOrCreate` on `(student_attempt_id, question_id, answer_option_id)` selected options (set of option ids from the request); idempotent.
  - `submit(StudentAttempt)` — call `ExamGradingService::gradeAttempt`, set `score_obtained` + `finished_at=now()`, redirect to `result`.
  - `result(StudentAttempt)` — render "Tu calificación es: X / Y" per PRD §4.1.

### Timer enforcement (strict, backend)
- A `checkTimer` middleware (or a guard in the `take`/`answer` actions) computes `now() > $attempt->started_at + $exam->duration_minutes minutes` on every request; if expired and not already graded, it auto-submits (calls the grading service, sets `finished_at`, redirects to `result`). Enforcement is server-side so the timer holds even if the student closes the browser. Client-side JavaScript only displays the countdown and refreshes the display every second.

### Wizard UI (one question at a time — cached decision)
- Three Livewire components for clean separation and testability:
  - `app/Livewire/Student/ExamStart.php` — start/confirm screen before the attempt is created.
  - `app/Livewire/Student/ExamTake.php` — the wizard: current question text, options as radio (SINGLE) or checkboxes (MULTIPLE), "Anterior"/"Siguiente" navigation, "Finalizar" on the last question, countdown display, autosave on navigation.
  - `app/Livewire/Student/ExamResult.php` — the "X / Y" result screen.
  Blade views under `resources/views/livewire/student/exam/{start,take,result}.blade.php`. The current-question pointer is stored as Livewire state (resume-on-reopen), with `started_at` as the single source of truth for elapsed time.

### Routes (`routes/web.php`, behind `['auth','role:STUDENT']`)
- `GET /examenes/{exam}/intentar` → `student.exam.start`
- `GET /examenes/{attempt}/tomar` → `student.exam.take`
- `POST /examenes/{attempt}/responder/{question}` → `student.exam.answer`
- `POST /examenes/{attempt}/finalizar` → `student.exam.submit`
- `GET /examenes/{attempt}/resultado` → `student.exam.result`

### Dashboard extension
- Extend `app/Livewire/Dashboard.php` + `dashboard.blade.php` with two sections: "Exámenes disponibles" (exams from `subscribedClasses` without a `student_attempts` row for the auth user, each with an "Iniciar examen" link to `student.exam.start`), and "Exámenes completados" (`StudentAttempt::where('student_id',Auth::id())->with('exam')`, showing exam title + "X / Y" score). A completed exam shows "Ya rendiste este examen" instead of a start button.

### Tests (Pest)
- `tests/Feature/ExamTakingTest.php` — start creates an attempt + redirects to take; answer saves + advances; submit grades + shows "X / Y"; strict MCQ rule for SINGLE (correct→points, incorrect→0) and MULTIPLE (all-correct-and-no-incorrect→full, partial→0).
- `tests/Feature/StudentAttemptTest.php` — model relationships; UNIQUE on (`student_id`,`exam_id`); cascade delete on attempt/student/exam.
- `tests/Feature/StudentAnswerTest.php` — UNIQUE on (`student_attempt_id`,`question_id`,`answer_option_id`); multiple rows for a MULTIPLE question; cascade delete on attempt/question/option.
- `tests/Feature/ExamTimerTest.php` — an attempt with `started_at = now() - (duration + 1) minutes` auto-submits on `take` and redirects to `result`; a fresh attempt does not.
- `tests/Feature/StudentDashboardTest.php` (extended) — "Exámenes disponibles" lists untaken exams; "Exámenes completados" lists taken exams with scores; "Iniciar examen" links to `start`; subscription required (403 if not subscribed).

### README
- New "Exam engine: student taking and auto-grading" section after the "Student auth and multi-class subscription" section, documenting the start→take→answer→submit→result flow, the strict timer, the strict MCQ grading rule, the 1-attempt constraint, and the deferred items (reports, email notifications, profile page, retakes, essay/manual grading, shuffling, scheduling).

## Capabilities

> This section is the CONTRACT between proposal and specs phases. Existing capability names are taken from `openspec/specs/`.

### New Capabilities
- `exam-attempt-data`: The `student_attempts` and `student_answers` tables (PRD §5.8/§5.9) and their Eloquent models, enforcing 1 attempt per student per exam and one row per selected option per question, with cascade deletes.
- `exam-grading`: The strict auto-grading engine applying the PRD §4.1 SINGLE/MULTIPLE rule, scoring `score_obtained` as a sum of correctly-answered `question.points`, exposed via `ExamGradingService::gradeAttempt`.
- `student-exam-taking`: The authenticated STUDENT exam-taking flow — start (or 403 if taken / not subscribed), wizard taking (one question at a time, radio/checkbox, autosave), strict server-enforced countdown timer with auto-submit on expiry, submit + grade, and the "X / Y" result page.

### Modified Capabilities
- `student-class-subscription`: the student dashboard gains "Exámenes disponibles" and "Exámenes completados" sections that query subscriptions and attempts; an available exam links to `student.exam.start`. Subscription to the exam's class becomes the gating condition for starting an exam (no subscription → 403).

### Unchanged Capabilities (no spec-level behavior change)
- `teacher-exam-management`, `exam-data-model` — the exam/question/option structure is consumed read-only by the taking flow; no requirement changes.
- `student-auth` — the Breeze auth stack is reused behind `role:STUDENT`; no requirement change.

## Approach

The attempt is the single source of truth: `started_at` defines elapsed time, `student_attempts(student_id, exam_id)` UNIQUE enforces one attempt, and `student_answers` UNIQUE-on-3-columns records multiple selections per MULTIPLE question (resolved open question — option (c)). The wizard is split into three Livewire components (`ExamStart`, `ExamTake`, `ExamResult`) for separation and easier testing (resolved open question). Timer enforcement is server-side on every `take`/`answer` request so it holds across browser closes; the client only displays the countdown (resolved open question). Grading is a pure service (`ExamGradingService::gradeAttempt`) invoked on submit or auto-submit, keeping the controller thin (resolved open question). The dashboard extension queries existing `subscribedClasses` and the new `StudentAttempt` rows. Test style follows the existing Pest suite (`ExamResourceTest`, `ClassInvitationFlowTest`, `StudentDashboardTest`).

## Impact

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/*_create_student_attempts_table.php` | New | PRD §5.8 attempt table; unique `(student_id,exam_id)`; cascade. |
| `database/migrations/*_create_student_answers_table.php` | New | PRD §5.9 answer table; unique `(student_attempt_id,question_id,answer_option_id)`; cascade. |
| `app/Models/{StudentAttempt,StudentAnswer}.php` | New | Eloquent models, fillable casts, relationships. |
| `app/Models/{User,Exam,Question,AnswerOption}.php` | Modified | Additive `hasMany`/`hasMany answers` relationships. |
| `app/Services/ExamGradingService.php` | New | Strict MCQ grading engine. |
| `app/Http/Controllers/Student/ExamController.php` | New | start/show/answer/submit/result actions. |
| `app/Http/Middleware/CheckTimer.php` (or inline guard) | New | Server-side timer enforcement + auto-submit. |
| `app/Livewire/Student/{ExamStart,ExamTake,ExamResult}.php` | New | Wizard UI components. |
| `resources/views/livewire/student/exam/{start,take,result}.blade.php` | New | Wizard views. |
| `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php` | Modified | Add "Exámenes disponibles" + "Exámenes completados" sections. |
| `routes/web.php` | Modified | Five `student.exam.*` routes behind `['auth','role:STUDENT']`. |
| `tests/Feature/{ExamTaking,StudentAttempt,StudentAnswer,ExamTimer}Test.php` | New | Behavior coverage. |
| `tests/Feature/StudentDashboardTest.php` | Modified | Assert new dashboard sections + subscription gate. |
| `README.md` | Modified | New exam engine section. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| **Schema shape**: `student_answers` UNIQUE on 3 columns `(student_attempt_id, question_id, answer_option_id)` instead of 2 — diverges from a naive reading of "one answer per question" but is required so MULTIPLE questions can record several selected options. | Med | Documented in migration + README; the grading service is written against this 3-column shape (iterates selected options per question). Spy test `StudentAnswerTest` pins the constraint. |
| **Strict timer enforcement**: server-side check on every `take`/`answer` request computes `now()` vs `started_at + duration_minutes`; clock skew between app and DB could misfire. | Med | Compute `now()` server-side (PHP `Carbon`), not DB-driven; tolerance is the request round-trip. Auto-submit is idempotent (guards `finished_at`). Covered by `ExamTimerTest`. |
| **Auto-submit when timer expires** silently finishes an in-progress attempt; a student mid-typing loses unsaved selections for the current question. | Med | Autosave on every question navigation in `ExamTake`; the only loss on auto-submit is the question being actively viewed. Result page shows the attempt graded. Documented in README. |
| **1 attempt constraint**: UNIQUE on `(student_id, exam_id)` means a failed/interrupted attempt blocks any re-take; a student who closed the browser mid-exam cannot start over. | Med | Intentional per product decision (1 attempt). The attempt is resumable (the wizard resumes from the stored current-question pointer and `started_at` is preserved). Re-takes explicitly out of scope. |
| **Browser closed mid-exam**: resume must reuse the same `started_at` (not reset the timer) and the wizard must restore the current question. | Med | `started_at` is immutable on the attempt; the wizard current-question pointer is Livewire state persisted per attempt; auto-submit fires if the resumed time has expired. Covered by `ExamTimerTest` resume path. |
| **Subscription gate**: a student not subscribed to the exam's class could attempt an exam they shouldn't. | Low | `start` checks `class_user` membership (reuses `subscribedClasses`); 403 otherwise. Covered by `StudentDashboardTest` + `ExamTakingTest`. |
| **Deferred essay/manual grading**: this change only auto-grades SINGLE and MULTIPLE; any future essay question type has no grading path. | Low | Out of scope; `QuestionType` enum currently has only `Single`/`Multiple`. README flags the limitation. |
| **Review budget**: this change touches models, a service, controller, middleware, three Livewire components, views, routes, and four test files — likely exceeds 400 authored lines. | Med | `sdd-tasks` will forecast and recommend chained PRs over `student-attempts/migration+models`, `grading-service`, `taking-wizard`, `dashboard+routes+tests` work units per the `ask-always` strategy. |

## Rollback Plan

- Drop the two migrations: `php artisan migrate:rollback` removes `student_answers` then `student_attempts` (reverse order).
- Delete `app/Models/StudentAttempt.php`, `app/Models/StudentAnswer.php`, `app/Services/ExamGradingService.php`, `app/Http/Controllers/Student/ExamController.php`, `app/Http/Middleware/CheckTimer.php`, and `app/Livewire/Student/{ExamStart,ExamTake,ExamResult}.php`.
- Delete `resources/views/livewire/student/exam/`.
- Remove the additive relationships (`attempts()`, `answers()`) added to `User`, `Exam`, `Question`, `AnswerOption`.
- Revert `app/Livewire/Dashboard.php` and `dashboard.blade.php` to the pre-change sections (remove "Exámenes disponibles"/"Exámenes completados").
- Remove the five `student.exam.*` routes from `routes/web.php` and the `CheckTimer` middleware alias if registered.
- Delete `tests/Feature/{ExamTaking,StudentAttempt,StudentAnswer,ExamTimer}Test.php` and revert the `StudentDashboardTest` extension.
- Revert the README exam engine section.
- No teacher-side data, exam/question/option data, or subscription pivot is affected: rollback only drops the student attempt/answer runtime.

## Dependencies

- `student-auth` — Breeze auth + `role:STUDENT` middleware alias (`CheckRole`); the taking flow is behind auth + role.
- `student-class-subscription` — `class_user` pivot and `User::subscribedClasses()` used as the subscription gate for starting an exam.
- `exam-data-model` — `exams`, `questions`, `answer_options` tables and `QuestionType` enum consumed by the grading engine and wizard.
- `teacher-exam-management` — `ExamResource` authoring flow; this change is read-only against exam content.
- `platform-scaffold` — Laravel 13 / Livewire / Pest runtime already in place from prior changes (no new composer dependency; no `laravel/breeze` re-install).

## Future Capabilities Enabled

- **Reports** (PDF + Excel via `barryvdh/laravel-dompdf` + `maatwebsite/excel`) — require `student_attempts`/`student_answers` rows, which this change produces.
- **Email notifications** ("your exam was graded, you got X/Y") — needs a configured mailer (currently no `MAIL_MAILER`).
- **Student profile page** — currently only the dashboard.
- **Re-take mechanism** — the 1-attempt constraint is enforced; retakes are intentionally deferred.
- **Manual grading for essay questions** — `QuestionType` is limited to `Single`/`Multiple` today; a future `Essay` case and manual grading flow build on this engine.
- **Question shuffling / randomization** and **exam scheduling** (`start_at`/`end_at` windows) — out of scope; the engine is agnostic to order/schedule.

## Success Criteria

- [ ] A subscribed student can start an exam exactly once; a second start aborts 403 and the dashboard shows "Ya rendiste este examen".
- [ ] A non-subscribed student cannot start the exam (403).
- [ ] The wizard shows one question at a time with radio (SINGLE) or checkboxes (MULTIPLE), saves answers idempotently, and lets the student navigate prev/next and "Finalizar".
- [ ] On submit (or timer expiry), the strict MCQ rule grades SINGLE (correct→points, incorrect→0) and MULTIPLE (all correct AND no incorrect selected→full points, else 0).
- [ ] The server-side timer auto-submits when `now() > started_at + duration_minutes`, even if the student closed the browser; an attempt with an expired `started_at` redirects straight to the result page on reopen.
- [ ] The result page shows "Tu calificación es: X / Y" per PRD §4.1.
- [ ] The dashboard shows "Exámenes disponibles" (untaken) and "Exámenes completados" (with scores) sections.
- [ ] Deleting a student/exam/question/option cascades correctly to attempts/answers per the foreign keys.
- [ ] Pest tests pass: `ExamTakingTest`, `StudentAttemptTest`, `StudentAnswerTest`, `ExamTimerTest`, extended `StudentDashboardTest`.

## Proposal Question Round (interactive mode)

Resolved product decisions are encoded as assumptions above (first slice = full change; grading on-submit; strict server-side timer). The open technical questions are resolved with recommendations already chosen (see Result Contract `open_questions_resolved`). No additional product questions are required before specs — the PRD §4.1/§5.8/§5.9 and the cached preflight fully constrain behavior. If the reviewer wants a second round, the candidate questions are: (1) should the resume-pointer be stored in the DB vs Livewire state? (recommend Livewire state keyed by attempt); (2) should auto-submit lock the current question's unsaved selection before grading? (recommend yes — autosave on every navigation, not on auto-submit).