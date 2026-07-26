# Tasks: Exam Engine (Student Taking, Strict Timer, Auto-Grading)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~900–1000 authored across 27 files |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: Data + Grading (~370 lines) → PR 2: UI + Tests + Docs (~580 lines) |
| Delivery strategy | ask-always |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Data layer + grading service (self-contained, testable in isolation) | PR 1 | Base: main. Includes migrations, models, relationships, ExamGradingService, and its tests. |
| 2 | Student exam UI, timer enforcement, dashboard, integration tests, docs | PR 2 | Depends on PR 1. ExamController, middleware, 3 Livewire components, 3 views, dashboard extension, full test suite, README. |

## Phase 1: Data Layer (PR 1)

- [x] 1.1 Create `*_create_student_attempts_table` migration: id, student_id FK, exam_id FK, score_obtained DECIMAL(8,2) nullable, started_at, finished_at nullable, timestamps; `unique(['student_id','exam_id'])`.
  **Deps**: none. **Verify**: `php artisan migrate:fresh --seed` succeeds.
- [x] 1.2 Create `*_create_student_answers_table` migration: id, student_attempt_id FK, question_id FK, answer_option_id FK, timestamps; `unique(['student_attempt_id','question_id','answer_option_id'])`.
  **Deps**: 1.1. **Verify**: `php artisan migrate` succeeds; `php artisan db:show --table=student_answers` shows columns.
- [x] 1.3 Create `app/Models/StudentAttempt.php`: `#[Fillable]`, `casts()` for timestamps, `student()`, `exam()`, `answers()` relationships.
  **Deps**: 1.1. **Verify**: `php artisan tinker --execute="App\Models\StudentAttempt::class"` loads.
- [x] 1.4 Create `app/Models/StudentAnswer.php`: `#[Fillable]`, `attempt()`, `question()`, `option()` relationships.
  **Deps**: 1.2. **Verify**: `php artisan tinker --execute="App\Models\StudentAnswer::class"` loads.
- [x] 1.5 Add `attempts(): HasMany` to `User`, `Exam`; add `answers(): HasMany` to `Question`, `AnswerOption`.
  **Deps**: 1.3, 1.4. **Verify**: Pest test `StudentAttemptTest::it('has correct relationships')` passes.

## Phase 2: Grading Service (PR 1)

- [x] 2.1 Create `app/Services/ExamGradingService.php`: `gradeAttempt(StudentAttempt): float` — iterate questions, apply SINGLE/MULTIPLE strict rules, idempotent if `finished_at` set.
  **Deps**: 1.3, 1.4. **Verify**: `php artisan tinker` — instantiate service, call `gradeAttempt` on a seeded attempt.
- [x] 2.2 Write `tests/Feature/ExamGradingServiceTest.php`: 7 scenarios covering SINGLE correct/incorrect/blank, MULTIPLE exact/extra/partial/blank, aggregate sum, and idempotency.
  **Deps**: 2.1. **Verify**: `vendor/bin/pest tests/Feature/ExamGradingServiceTest.php` — all green.

## Phase 3: Model Tests (PR 1 → deferred to PR 2)

- [ ] 3.1 Write `tests/Feature/StudentAttemptTest.php`: table columns, UNIQUE constraint (duplicate blocked, cross-exam allowed), cascade delete on student + exam, relationship resolution.
  **Deps**: 1.1, 1.2, 1.5. **Verify**: `vendor/bin/pest tests/Feature/StudentAttemptTest.php` — all green.

## Phase 4: Controller, Middleware & Routes (PR 2)

- [ ] 4.1 Create `app/Http/Controllers/Student/ExamController.php` with 5 actions: `start`, `show`, `answer`, `submit`, `result`. Auth+role:STUDENT+subscription checks on start; idempotent `updateOrCreate` on answer; `gradeAttempt` on submit.
  **Deps**: 2.1. **Verify**: `php artisan route:list --name=student.exam` shows 5 named routes.
- [ ] 4.2 Create `app/Http/Middleware/CheckExamTimer.php`: compute remaining time; auto-submit (grade + redirect to result) if expired. Register `checkTimer` alias in `bootstrap/app.php`.
  **Deps**: 2.1. **Verify**: `php artisan tinker --execute="app('Illuminate\Contracts\Http\Kernel')->getMiddlewareGroups()"` confirms alias.
- [ ] 4.3 Register 5 `student.exam.*` routes in `routes/web.php` behind `['auth','role:STUDENT']`; add `checkTimer` to take+answer routes.
  **Deps**: 4.1, 4.2. **Verify**: `php artisan route:list --path=examenes` lists start/take/answer/submit/result.

## Phase 5: Livewire Wizard (PR 2)

- [ ] 5.1 Create `app/Livewire/Student/ExamStart.php` + `resources/views/livewire/student/exam/start.blade.php`: confirm screen, link to `student.exam.start`.
  **Deps**: 4.3. **Verify**: `php artisan livewire:list` shows `student.exam-start`.
- [ ] 5.2 Create `app/Livewire/Student/ExamTake.php` + `resources/views/livewire/student/exam/take.blade.php`: one-question wizard, radio/checkbox per type, countdown display, navigation, "Finalizar" on last question.
  **Deps**: 4.3. **Verify**: `php artisan livewire:list` shows `student.exam-take`.
- [ ] 5.3 Create `app/Livewire/Student/ExamResult.php` + `resources/views/livewire/student/exam/result.blade.php`: display "Tu calificación es: X / Y" from `score_obtained` / `exam.max_score`.
  **Deps**: 4.3. **Verify**: `php artisan livewire:list` shows `student.exam-result`.

## Phase 6: Dashboard Extension (PR 2)

- [ ] 6.1 Extend `app/Livewire/Dashboard.php` + `resources/views/livewire/dashboard.blade.php`: query available exams (subscribed class exams without attempt) and completed exams (with score). Render "Exámenes disponibles" + "Exámenes completados" sections.
  **Deps**: 1.5, 4.3. **Verify**: `vendor/bin/pest tests/Feature/StudentDashboardTest.php` — existing + new scenarios pass.

## Phase 7: Integration Tests (PR 2)

- [ ] 7.1 Write `tests/Feature/ExamTakingTest.php`: start (403 if taken/unsubscribed, 302 if fresh), answer idempotency, question advancement, submit→grade, result "X / Y", ungraded redirects to take.
  **Deps**: 4.1, 5.2, 5.3. **Verify**: `vendor/bin/pest tests/Feature/ExamTakingTest.php` — all green.
- [ ] 7.2 Write `tests/Feature/ExamTimerTest.php`: expired timer auto-submits on take + answer routes; browser-close resume auto-submits if timer elapsed.
  **Deps**: 4.2, 4.3. **Verify**: `vendor/bin/pest tests/Feature/ExamTimerTest.php` — all green.
- [ ] 7.3 Run full test suite: `vendor/bin/pest`. All existing 104+ tests plus new tests must pass. No regressions.

## Phase 8: Documentation (PR 2)

- [ ] 8.1 Update `README.md`: add exam engine section documenting student flow (dashboard→start→wizard→result), timer enforcement, and the grading rules.
  **Deps**: 7.3 (all features verified). **Verify**: `Get-Content README.md | Select-String "Exam Engine"` returns a match.
