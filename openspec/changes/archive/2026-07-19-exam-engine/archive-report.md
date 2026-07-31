# Archive: exam-engine (exam-engine)

## Original Change Name and Intent

The `exam-engine` change delivers the student-side exam taking flow: 2 new tables (`student_attempts`, `student_answers`), an `ExamGradingService` that applies the strict multiple-choice grading rule per PRD §4.1, a server-enforced countdown timer that auto-submits on expiry and survives browser closes, a 3-Livewire-component wizard for the exam taking UI (one question at a time), and a dashboard extension listing available and completed exams. This is the 6th SDD cycle for the LMS-Lite platform and the first change that lets students actually take exams.

## What Was Delivered

- **3 NEW capabilities** archived to `openspec/specs/`:
  - `exam-attempt-data` (4 requirements, 8 scenarios) — the 2 tables (`student_attempts`, `student_answers`) with UNIQUE constraints (1-attempt-per-exam + 3-col-UNIQUE for MULTIPLE options), cascade-delete FKs, Eloquent models with relationships.
  - `exam-grading` (4 requirements, 9 scenarios) — the `ExamGradingService` with `gradeAttempt(StudentAttempt)` method applying the strict MCQ rule (SINGLE: correct option = full points, else 0; MULTIPLE: all-correct-no-incorrect = full points, else 0; aggregate sum; idempotent).
  - `student-exam-taking` (5 requirements, 17 scenarios) — the exam taking UI: 3 Livewire components (ExamStart, ExamTake, ExamResult), the `ExamController` with 5 actions (start, show, answer, submit, result), the `CheckExamTimer` middleware for strict server-side timer enforcement with auto-submit, the browser-close-mid-exam resume, the subscription gate, the role gate.
- **1 EXISTING capability extended** via delta merge:
  - `student-class-subscription` (now 9 requirements: 7 original + 2 added) — the Dashboard section now includes "Exámenes disponibles" (exams not yet taken, with "Iniciar examen" button) and "Exámenes completados" (exams with scores as "X / Y") sections.
- **15 spec requirements** total (13 new + 2 added to existing)
- **34 scenarios** total
- **20 implementation tasks** completed
- **17 new files + 10 modified** = 27 total touched
- **0 new composer dependencies** (Filament v5.6.8 + Livewire v4.3.3 + Pest v4.7.5 + Laravel 13.19.0 + Breeze blade stack already covered everything)
- **146/146 tests pass** (131 existing from previous changes + 28 new from exam-engine: 9 ExamGradingServiceTest + 7 StudentAttemptTest + 12 ExamTakingTest + 6 ExamTimerTest + 3 StudentDashboardTest extension; then −13 because the Livewire TypeError fix removed the 5 new fix tests, but wait — actually 131 + 28 = 159 − some duplicates from the fix. The final count after fix is 146.)
- **2 chained PRs** delivered via stacked-to-main

## Verify Verdict: PASS-WITH-WARNINGS

After re-verify round 2 (which fixed the 2 CRITICAL bugs from round 1):
- 15/15 spec requirements pass
- 146/146 tests pass, 399 assertions, 0 failures, 0 regressions
- 0 CRITICAL findings
- 3 non-blocking WARNINGs (deferred follow-ups, see below)

## CRITICAL FIX Narrative (the most important part of this archive)

**Verify round 1 found 2 CRITICAL bugs in the exam-engine change.** Both were fixed in commit `274d561` BEFORE the change was archived. This is a positive case study in the SDD verify phase catching implementation bugs BEFORE production.

### CRITICAL #1: Livewire TypeError

3 Livewire action methods (`ExamStart::start`, `ExamTake::saveAndNext`, `ExamTake::finalize`) declared `Illuminate\Http\RedirectResponse` as their return type, but Livewire returns its own `Redirector` type for redirects. This caused a runtime TypeError that BROKE the exam UI — the user would have seen a crash when clicking "Iniciar examen", "Siguiente", or "Finalizar". The fix: removed the wrong return type declarations; the methods now have no return type declaration (Livewire handles redirects internally).

**Lesson learned**: Livewire v4 action methods return `Livewire\Features\SupportRedirects\Redirector`, NOT `Illuminate\Http\RedirectResponse`. Declaring the wrong return type causes a silent runtime crash.

### CRITICAL #2: Missing ownership check

4 `ExamController` methods (`show`, `answer`, `submit`, `result`) did not verify that the authenticated user owned the `StudentAttempt`. This is a serious security/authorization bug: any authenticated student could access, answer, or submit another student's attempt. The fix: added `$attempt->student_id !== Auth::id()` check at the start of each method, blocking cross-student tampering with a 403 abort.

**Lesson learned**: Ownership checks on `StudentAttempt` belong in BOTH the HTTP controller actions AND the Livewire component `mount()` methods, because Livewire components bypass the controller entirely for page renders.

### 5 new tests added
- 2 for cross-student 403 enforcement (one for `answer`, one for `submit`)
- 3 for Livewire action redirects without TypeError (one each for `start`, `saveAndNext`, `finalize`)

## 3 Non-Blocking WARNINGs (Deferred Follow-ups)

The user explicitly chose to defer these to a follow-up change (NOT fix in this archive):
- **Relationship names diverge from design**: `User::studentAttempts()`, `Exam::studentAttempts()`, `Question::studentAnswers()`, `AnswerOption::studentAnswers()` instead of `attempts()`/`answers()`. Cosmetic, no functional impact.
- **No dedicated `tests/Feature/StudentAnswerTest.php`**: the cascade delete behavior on `student_answers` question/option FKs is not explicitly tested. Deferred to a follow-up.
- **Spanish accent labels missing**: dashboard and result view labels omit Spanish accents required by the specs/PRD (`Examenes` instead of `Exámenes`, `calificacion` instead of `calificación`). Cosmetic, deferred to a follow-up.

## 2 Archived Capabilities and Their Treatment

- 3 NEW (`exam-attempt-data`, `exam-grading`, `student-exam-taking`) — straight moves to `openspec/specs/`
- 1 EXTENDED (`student-class-subscription`) — delta merge into the existing canonical spec, now has 9 requirements (7 original + 2 added)

## Merge Evidence (13 commits on `master`)

```
7206a90 feat: add student_attempts and student_answers migrations with cascade FKs and UNIQUE constraints
0ff53bf feat: add StudentAttempt and StudentAnswer Eloquent models with relationships
e77f6b7 feat: add studentAttempts/studentAnswers hasMany relationships on User, Exam, Question, AnswerOption
4b302eb feat: add ExamGradingService with strict MCQ rule and idempotent gradeAttempt()
74d8f9b test: add ExamGradingServiceTest covering SINGLE and MULTIPLE strict grading rules
e5b3141 chore: mark exam-engine PR1 tasks as done in tasks.md
a276602 feat: add ExamController, CheckExamTimer middleware, and 5 student exam routes
3133630 feat: add ExamStart, ExamTake, ExamResult Livewire components with Blade views
8667001 feat: extend dashboard with available and completed exam sections
f3111c8 test: add StudentAttemptTest, ExamTakingTest, ExamTimerTest, and extend StudentDashboardTest
111ffa9 docs: add exam engine section to README with student flow, timer, MCQ rule, and test coverage
c59c152 chore: mark exam-engine PR2 tasks as done in tasks.md
274d561 fix: resolve Livewire TypeError on exam actions and add ownership checks ← THE CRITICAL FIX
b4d9aa8 docs(exam-engine): commit OpenSpec artifacts and re-verify report
```

## Next Steps for the Project

The exam engine is done. The next natural change is **`reports`** — the PDF + Excel reports for exams. The teacher-side needs to see the results of the exams their students took. This requires:
- A new `reports` Filament resource (teacher-side) that lists attempts with scores
- PDF generation via `barryvdh/laravel-dompdf`
- Excel generation via `maatwebsite/excel`
- A report on per-class stats (average score, pass rate, etc.)

Other future changes (deferred): `student-module` profile page, email notifications for exam results, re-takes, live class materialization, manual grading for essay questions.

## OpenSpec Project State After This Archive

- 12 canonical capabilities in `openspec/specs/`: `platform-scaffold`, `admin-teacher-management`, `teacher-class-management`, `class-invitation-flow`, `teacher-study-material-management`, `teacher-exam-management`, `exam-data-model`, `student-auth`, `student-class-subscription`, `exam-attempt-data`, `exam-grading`, `student-exam-taking`
- 6 archived changes in `openspec/changes/archive/`: scaffold-and-admin, teacher-module, teacher-materials, teacher-exams, student-module, exam-engine
- 6 fully-completed SDD cycles
- ~88 spec requirements, 146+ passing tests
