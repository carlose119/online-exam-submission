# Design: Exam Engine (Student Taking, Strict Timer, Auto-Grading)

## Technical Approach

This change adds the student exam-taking subsystem atop the Laravel 13/Filament v5/Livewire v4/Breeze stack built in prior changes. The architecture layers are: (1) 2 new DB tables with Eloquent models and `#[Fillable]` attributes, (2) a pure grading service (`ExamGradingService::gradeAttempt`), (3) an `ExamController` with 5 actions + a `CheckExamTimer` middleware for server-side timer enforcement, (4) 3 Livewire components for the wizard UI (`ExamStart`, `ExamTake`, `ExamResult`), and (5) a dashboard extension querying `subscribedClasses` and `StudentAttempt`. No new Composer dependencies — Filament v5.6.8, Livewire v4.3.3, Pest v4.7.5, and Laravel 13.19.0 already cover every capability.

## Architecture Decisions

### Decision: Wizard Implementation

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Single monolithic Livewire component | Simpler routing, harder to test/resume | Rejected |
| 3 separate Livewire components | Clean separation, independently testable, matches existing `Dashboard` pattern | **Chosen** |
| Server-rendered Blade with HTMX | No Livewire dependency, more manual state management | Rejected |

**Rationale**: Three components align with the natural boundaries (start → take → result). Each has a single responsibility and can fail independently. Follows the existing `app/Livewire/Dashboard.php` convention with `#[Layout('layouts.app')]`.

### Decision: Timer State Storage

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Client-only JavaScript countdown | Doesn't survive browser close, exploitable | Rejected |
| DB column `remaining_seconds` | Write-heavy (must update every second), inconsistent | Rejected |
| Compute `now() - started_at` server-side per request | Stateless, survives browser close, single DB write at start | **Chosen** |

**Rationale**: Server is the single source of truth (PRD §6.1). Client JS only displays the countdown. Auto-submit fires on the first request after expiry, even after browser close.

### Decision: Grading Service Interface

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Controller-inline grading logic | Tighter coupling, untestable in isolation | Rejected |
| Action class per question type | Over-abstracted for two question types | Rejected |
| `ExamGradingService::gradeAttempt(StudentAttempt): float` | Pure service, single entry point, idempotent | **Chosen** |

## Data Flow

```
Student dashboard → "Iniciar examen" link
    │
    ▼
ExamStart Livewire component → ExamController::start
    │  Validates: auth + role:STUDENT + class_user pivot + no existing attempt
    │  Creates StudentAttempt (started_at=now, finished_at=null)
    ▼
ExamTake Livewire component (wizard, one question at a time)
    │  ┌── on every request: CheckExamTimer middleware
    │  │      if now() > started_at + duration_minutes → auto-submit → result
    │  ├── POST /responder/{question} → ExamController::answer
    │  │      updateOrCreate student_answers rows (idempotent)
    │  │      advance to next question (or show "Finalizar" on last)
    │  └── POST /finalizar → ExamController::submit
    │         ExamGradingService::gradeAttempt → sets score_obtained + finished_at
    ▼
ExamResult Livewire component
    │  Displays "Tu calificación es: X / Y"
    ▼
Dashboard ← "Exámenes completados" updated with score
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*_create_student_attempts_table.php` | Create | PRD §5.8: UNIQUE(student_id, exam_id), cascade FKs |
| `database/migrations/*_create_student_answers_table.php` | Create | PRD §5.9: 3-column UNIQUE, cascade FKs |
| `app/Models/StudentAttempt.php` | Create | `#[Fillable]` + `casts()` + `student()`, `exam()`, `answers()` |
| `app/Models/StudentAnswer.php` | Create | `#[Fillable]` + `attempt()`, `question()`, `option()` |
| `app/Models/User.php` | Modify | Additive `attempts(): HasMany` |
| `app/Models/Exam.php` | Modify | Additive `attempts(): HasMany` |
| `app/Models/Question.php` | Modify | Additive `answers(): HasMany` |
| `app/Models/AnswerOption.php` | Modify | Additive `answers(): HasMany` |
| `app/Services/ExamGradingService.php` | Create | `gradeAttempt(StudentAttempt): float` — strict MCQ rule |
| `app/Http/Controllers/Student/ExamController.php` | Create | start, show, answer, submit, result (5 actions) |
| `app/Http/Middleware/CheckExamTimer.php` | Create | Server-side timer enforcement + auto-submit |
| `app/Livewire/Student/ExamStart.php` | Create | Start confirmation screen |
| `app/Livewire/Student/ExamTake.php` | Create | Wizard: question, radio/checkbox, navigation, countdown |
| `app/Livewire/Student/ExamResult.php` | Create | "X / Y" result display |
| `resources/views/livewire/student/exam/start.blade.php` | Create | Start view |
| `resources/views/livewire/student/exam/take.blade.php` | Create | Take/wizard view |
| `resources/views/livewire/student/exam/result.blade.php` | Create | Result view |
| `app/Livewire/Dashboard.php` | Modify | Add available/completed exam queries |
| `resources/views/livewire/dashboard.blade.php` | Modify | Render "Exámenes disponibles" + "completados" |
| `routes/web.php` | Modify | 5 `student.exam.*` routes behind auth+role:STUDENT |
| `bootstrap/app.php` | Modify | Register `checkTimer` middleware alias |
| `tests/Feature/ExamTakingTest.php` | Create | Start/answer/submit/result behavior |
| `tests/Feature/StudentAttemptTest.php` | Create | Model relationships + UNIQUE constraints + cascade |
| `tests/Feature/ExamTimerTest.php` | Create | Timer enforcement + resume-after-close |
| `tests/Feature/ExamGradingServiceTest.php` | Create | SINGLE/MULTIPLE grading edge cases |
| `tests/Feature/StudentDashboardTest.php` | Modify | Assert new exam sections on dashboard |
| `README.md` | Modify | Add exam engine section documenting the flow |

## Interfaces / Contracts

**ExamGradingService** — single entry point, idempotent (no-op if `finished_at` already set):
```php
namespace App\Services;
class ExamGradingService {
    public function gradeAttempt(StudentAttempt $attempt): float;
}
```

**ExamController** — 5 standard Laravel controller actions under `App\Http\Controllers\Student`:
- `start(Request, Exam)` → 403 if taken/unsubscribed, else create attempt + redirect
- `show(Request, StudentAttempt)` → render current wizard question
- `answer(Request, StudentAttempt, Question)` → idempotent save + advance
- `submit(Request, StudentAttempt)` → grade + redirect to result
- `result(Request, StudentAttempt)` → render "X / Y" (redirects to take if ungraded)

**CheckExamTimer middleware** — aliased as `checkTimer` in `bootstrap/app.php`, applied to take + answer routes. Computes `now() > $attempt->started_at + $attempt->exam->duration_minutes` and auto-submits when expired.

**Data model uniqueness**: `student_attempts UNIQUE(student_id, exam_id)` enforces 1 attempt; `student_answers UNIQUE(student_attempt_id, question_id, answer_option_id)` enables multi-row selections for MULTIPLE questions per proposal §Risks.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Start (403 taken/unsubscribed, 201 new) | `actingAs($student)` + HTTP assertions |
| Feature | Answer idempotency + question navigation | `actingAs()` + DB assertions on `student_answers` |
| Feature | Submit → grade → "X / Y" result | `actingAs()` + `ExamGradingService` + view assertions |
| Feature | Timer auto-submit on expired attempt | Create attempt with past `started_at`, assert redirect to result |
| Feature | Browser-close resume → auto-submit if expired | Same timer test, preserve `started_at` |
| Feature | Model relationships + cascade + UNIQUE constraints | Eloquent assertions, `expect()->toBeNull()` |
| Feature | SINGLE correct/incorrect/blank, MULTIPLE strict rule | Service test: correct→points, partial/incorrect→0 |
| Feature | Dashboard exam sections | `actingAs()` + `assertSee()` "Exámenes disponibles" / "completados" |

All tests use Pest v4.7.5 with `RefreshDatabase` (auto-applied in `tests/Pest.php`), SQLite `:memory:`, and `actingAs()`. No PHPUnit or Dusk. **Tests are written AFTER implementation** (`strict_tdd: false` in `openspec/config.yaml`).

## Migration / Rollout

No data migration — both tables are net-new. Rollback: `php artisan migrate:rollback` + delete new files (16-step list in proposal §Rollback Plan). Teacher-side data (exams, questions, options, subscriptions) is never modified.

## Open Questions

None. All technical decisions resolved in proposal + affirmed by 3 delta specs (4+4+5+2 requirements, 34 scenarios).
