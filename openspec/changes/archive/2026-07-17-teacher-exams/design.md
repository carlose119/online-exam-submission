# Design: Teacher Exams (Builder and Question Types)

## Technical Approach

Add three cascaded tables (`exams` → `questions` → `answer_options`) with cascade-delete FKs, a `QuestionType` backed enum, three Eloquent models with `#[Fillable]` attributes, and a single Filament v5 `ExamResource` with nested Repeaters for authoring complete exams in one form. Query scope limits access to classes owned by `Auth::id()`, matching the `StudyMaterialResource` and `ClassResource` patterns. No new Composer dependencies needed — Filament v5.6.8, Pest v4.7.5, and PHP 8.1+ backed enums already cover all requirements.

References: `teacher-exam-management` spec (8 reqs, 9 scenarios), `exam-data-model` spec (5 reqs, 5 scenarios), PRD §§3.3/5.5-5.7.

## Architecture Decisions

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| 1 | Form strategy | Single `ExamResource` with nested Repeaters | Mirrors PRD §3.3. Teacher authors exam + questions + options in one page. Avoids multi-step wizards that complicate cascade management. |
| 2 | `max_score` defaulting | Compute sum of question points in `mutateFormDataBeforeCreate`; allow explicit override | Matches `teacher-exam-management` req #3. Non-blocking warning on mismatch. Helper text "Defaults to sum of question points" informs the teacher. |
| 3 | Question `order` column | Integer `order`, renumbered sequentially from Repeater position in `mutateFormDataBeforeSave` | `teacher-exam-management` req #6. True drag-and-drop Repeater reorder is not a stock Filament v5 feature; sequential renumbering is sufficient for authoring. |
| 4 | Type-switch behavior | `live()` + `afterStateUpdated` fires a Filament `Notification::warning()`; does NOT auto-clear `is_correct` flags | `teacher-exam-management` req #2. Non-blocking warning respects the teacher's existing data. Clear-the-flags behavior would destroy work. |
| 5 | Cascade delete | `onDelete('cascade')` on all 3 FKs (class_id, exam_id, question_id) | DB-level enforcement. No orphaned records. `ExamResource` delete action includes a confirmation modal. |
| 6 | Query scope | `whereHas('classroom', fn($q) => $q->where('teacher_id', Auth::id()))` | Exact same pattern as `StudyMaterialResource` and `ClassResource`. Cross-teacher access returns 404. |

## Data Flow

```
Filament Form (ExamResource)
  │
  ├─ Exam Details section: title, description, duration_minutes, max_score
  │
  └─ Questions Repeater (minItems:1)
       │
       ├─ text Textarea, type Select (live), points TextInput
       │
       └─ Options sub-Repeater (minItems:2)
            └─ text TextInput, is_correct Toggle

mutateFormDataBeforeCreate() ──→ defaults max_score, sets question order
mutateFormDataBeforeSave()   ──→ renumbers order sequentially (1,2,3,...)

Database: exams ──1─N──→ questions ──1─N──→ answer_options
                    cascade              cascade
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*_create_exams_table.php` | Create | id, class_id FK→classes cascadeOnDelete, title, description nullable, duration_minutes, max_score, timestamps |
| `database/migrations/*_create_questions_table.php` | Create | id, exam_id FK→exams cascadeOnDelete, text, type enum(SINGLE,MULTIPLE), points, order, timestamps; index(exam_id, order) |
| `database/migrations/*_create_answer_options_table.php` | Create | id, question_id FK→questions cascadeOnDelete, text, is_correct boolean, timestamps |
| `app/Enums/QuestionType.php` | Create | Backed enum: Single='SINGLE', Multiple='MULTIPLE'; methods getLabel(), getColor(), getIcon() mirroring StudyMaterialType |
| `app/Models/Exam.php` | Create | `#[Fillable]`, `classroom()` belongsTo, `questions()` hasMany |
| `app/Models/Question.php` | Create | `#[Fillable]`, `QuestionType` cast, `exam()` belongsTo, `options()` hasMany (ordered by id ASC) |
| `app/Models/AnswerOption.php` | Create | `#[Fillable]`, `is_correct` boolean cast, `question()` belongsTo |
| `app/Models/SchoolClass.php` | Modify | Add `exams(): HasMany` relationship |
| `app/Filament/Resources/ExamResource.php` | Create | Form: exam details section + questions Repeater + options sub-Repeater. Table: title/classroom.title/duration/min_score/question-count Badges. getEloquentQuery() scoped to teacher. |
| `app/Filament/Resources/ExamResource/Pages/ListExams.php` | Create | ListRecords stub |
| `app/Filament/Resources/ExamResource/Pages/CreateExam.php` | Create | CreateRecord with mutateFormDataBeforeCreate (max_score defaulting, question order) |
| `app/Filament/Resources/ExamResource/Pages/EditExam.php` | Create | EditRecord with mutateFormDataBeforeSave (order renumbering), preview header action |
| `tests/Feature/ExamResourceTest.php` | Create | CRUD, type-switch notification, query scope, minItems, cascade delete, order renumbering, class Select scoping |
| `tests/Feature/QuestionModelTest.php` | Create | Relationship resolution, QuestionType enum cast |
| `tests/Feature/AnswerOptionModelTest.php` | Create | Relationship resolution, is_correct boolean cast |
| `README.md` | Modify | Add "Teacher exams" section after "Teacher materials" |

## Interfaces / Contracts

**`QuestionType` enum** (mirrors `StudyMaterialType`):
```php
enum QuestionType: string {
    case Single = 'SINGLE';
    case Multiple = 'MULTIPLE';
    // getLabel(): string, getColor(): string, getIcon(): string
}
```

**Model relationship signature**: `SchoolClass::exams(): HasMany`, `Exam::classroom(): BelongsTo`, `Exam::questions(): HasMany`, `Question::options(): HasMany`, `Question::exam(): BelongsTo`, `AnswerOption::question(): BelongsTo`. All FK columns use `onDelete('cascade')`. All models use `#[Fillable]` PHP attributes and `casts()` method for type coercion.

**ExamResource query scope**: `parent::getEloquentQuery()->whereHas('classroom', fn ($q) => $q->where('teacher_id', Auth::id()))`. `class_id` Select loads via `SchoolClass::where('teacher_id', Auth::id())->pluck('title', 'id')`.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | ExamResource CRUD, form validation, query scope, cascade delete | Pest v4 with `RefreshDatabase`, `actingAs($teacher)`, Livewire test for form interactions, Eloquent assertions for DB state |
| Feature | Model relationships and casts | Pest v4 with in-memory SQLite, `create()` and `expect()` assertions on enum/boolean casts |
| Smoke | Filament page renders | Livewire `assertOk()` on List/Create/Edit pages |

Tests written AFTER implementation (not test-first per `config.yaml`). No new testing dependencies — `pest-plugin-laravel` already provides Livewire testing support.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. This change is a pure CRUD resource on the existing Filament `/admin` panel.

## Migration / Rollout

Run `php artisan migrate` to create the three new tables. Rollback: `php artisan migrate:rollback` (drop in reverse order: answer_options → questions → exams). No data migration, feature flags, or phased rollout required. `README.md` receives an additive section.

## Open Questions

- [ ] None — all resolved in the proposal phase. Design decisions are final and ready for tasks.
