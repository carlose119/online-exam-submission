# Proposal: Teacher Exams (Builder and Question Types)

## Intent (Why)

Teachers can manage classes (`teacher-class-management`) and attach learning content (`teacher-study-material-management`), but cannot yet author exams. PRD §3.3 requires a teacher exam builder: general data (title, description, duration in minutes, max score), a dynamic Repeater for N questions (text, type SINGLE/MULTIPLE, points), and a sub-Repeater of answer options (text + `is_correct` flag). This change adds the first exams slice — the three PRD §5.5-5.7 tables (`exams`, `questions`, `answer_options`) and a Filament v5 `ExamResource` that lets a teacher build a complete exam in one form. The strict MCQ grading rule (PRD §4.1) is deferred: the builder only collects `is_correct` flags; validation of "all correct AND no incorrect selected" lives in the future student/grading engine.

## What Changes

- New `exams` migration per PRD §5.5: `id`, `class_id` FK → `classes.id` `onDelete('cascade')`, `title` VARCHAR(255), `description` TEXT nullable, `duration_minutes` INTEGER, `max_score` INTEGER, timestamps.
- New `questions` migration per PRD §5.6: `id`, `exam_id` FK → `exams.id` `onDelete('cascade')`, `text` TEXT, `type` enum [SINGLE, MULTIPLE] default SINGLE, `points` INTEGER default 1, `order` INTEGER default 0, timestamps. Index `questions(exam_id, order)`.
- New `answer_options` migration per PRD §5.7: `id`, `question_id` FK → `questions.id` `onDelete('cascade')`, `text` TEXT, `is_correct` BOOLEAN default false, timestamps.
- New `QuestionType` backed enum (PHP 8.1+) with cases `Single` / `Multiple` and `getLabel()/getColor()/getIcon()` helpers mirroring `StudyMaterialType`.
- New `Exam`, `Question`, `AnswerOption` Eloquent models with `#[Fillable]` PHP attributes, modern casts (`QuestionType` enum cast, boolean cast on `is_correct`), and relationships (`Exam->classroom()`, `Exam->questions()`, `Question->options()`, `AnswerOption->question()`).
- `SchoolClass::exams()` hasMany added (clean ergonomics).
- New `ExamResource` (Filament v5, `/admin` panel, matches `StudyMaterialResource` / `ClassResource` conventions):
  - Form sections: exam details (`title`, `description` Textarea, `duration_minutes` numeric, `max_score` numeric with helper text "Defaults to the sum of question points") → questions `Repeater`.
  - Each question in the Repeater: `text` Textarea, `type` Select (Single/Multiple) with `live()` + `afterStateUpdated` that fires a Filament Notification warning the teacher to review `is_correct` flags (does NOT auto-clear; does NOT block), `points` numeric, options sub-`Repeater`.
  - Each option in the sub-Repeater: `text` TextInput, `is_correct` Toggle.
  - Validation: questions Repeater `minItems(1)`; options sub-Repeater `minItems(2)`; `max_score >= sum(points)` surfaced as a warning (not a blocking error); each question must have at least 1 correct option.
  - Table columns: `title` (searchable/sortable), `classroom.title` (relationship, searchable), `duration_minutes` Badge "X min", `max_score` Badge, questions count via `withCount('questions')`, options count via `withCount('questions.options')`, `created_at` (sortable). Actions: Edit, Delete, and a "Preview as student" header action on the Edit page rendering a formatted/JSON view of questions + options.
  - Query scope: `getEloquentQuery()->whereHas('classroom', fn ($q) => $q->where('teacher_id', Auth::id()))` (same pattern as `StudyMaterialResource`).
  - `class_id` Select searchable from the teacher's own classes; `mutateFormDataBeforeCreate()` defaults `max_score` to the sum of points when left blank; `mutateFormDataBeforeSave()` renumbers `order` sequentially (1, 2, 3, …) from the Repeater's natural order.
- Page stubs: `ListExams`, `CreateExam`, `EditExam` in `app/Filament/Resources/ExamResource/Pages/`.
- Pest tests: `ExamResourceTest` (create exam with ≥1 question × ≥2 options, `type` switch fires warning without clearing flags, cross-teacher isolation via query scope, min-items validation) plus `QuestionModelTest` and `AnswerOptionModelTest` (relationships, `QuestionType` cast, `is_correct` boolean cast).
- README: new "Teacher exams: builder and question types" section after the "Teacher materials" section.

## Capabilities

### New Capabilities
- `teacher-exams`: Teacher-scoped CRUD over exams, questions, and answer options via a single Filament v5 `ExamResource` with nested Repeaters. Query scope limits an exam to classes owned by `Auth::id()`. Excludes grading, student attempts, scheduling, and publishing lifecycle.

### Modified Capabilities
- None. `teacher-class-management` and `teacher-study-material-management` are unaffected at the spec level; only `SchoolClass` gains an Eloquent `exams()` hasMany (additive, non-behavioral). The public invitation page (`class-invitation-flow`) is NOT extended — the deferred TBD placeholder for exams remains untouched.

## Approach

One Filament v5 `ExamResource` over three cascaded tables, mirroring the conditional-form and query-scope auth patterns already proven by `StudyMaterialResource`/`ClassResource`. A questions `Repeater` with an inline options sub-`Repeater` lets the teacher author a whole exam on a single page. `max_score` defaults (on create) to the sum of question points and is explicitly overridable. Strict MCQ grading is intentionally NOT implemented here: the builder collects `is_correct` flags and only enforces "≥1 correct per question"; the all-correct-AND-no-incorrect rule (PRD §4.1) is evaluated at grading time in the deferred student module.

## Impact

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/*_create_exams_table.php` | New | `exams` table with cascade FK to `classes`. |
| `database/migrations/*_create_questions_table.php` | New | `questions` table with cascade FK to `exams`, `order` column, composite index `(exam_id, order)`. |
| `database/migrations/*_create_answer_options_table.php` | New | `answer_options` table with cascade FK to `questions`. |
| `app/Enums/QuestionType.php` | New | Backed enum Single/Multiple with label/color/icon helpers. |
| `app/Models/Exam.php` | New | Eloquent model, Fillable attribute, casts, `classroom()` + `questions()`. |
| `app/Models/Question.php` | New | Fillable, `QuestionType` cast, `exam()` + `options()`. |
| `app/Models/AnswerOption.php` | New | Fillable, `is_correct` boolean cast, `question()`. |
| `app/Models/SchoolClass.php` | Modified | Add `exams()` hasMany (additive only). |
| `app/Filament/Resources/ExamResource.php` | New | Nested-Repeater form, scoped table, preview action. |
| `app/Filament/Resources/ExamResource/Pages/{List,Create,Edit}Exams.php` | New | Page stubs. |
| `tests/Feature/ExamResourceTest.php` | New | CRUD, type-switch warning, query scope, min validation. |
| `tests/Feature/{Question,AnswerOption}ModelTest.php` | New | Model relationships and casts. |
| `README.md` | Modified | New exams section after materials section. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Cascade delete: deleting a class silently removes all its exams → questions → options | Med | All three FKs use `onDelete('cascade')` per scope decision. `ExamResource` Delete action MUST present a confirmation modal naming the exam and warning that its questions/options are also removed; `ClassResource` class-delete is already cascading. Document in README. |
| `order` column management — drag-and-drop reorder not a stock Filament v5 Repeater feature (no stable item-sort API out of the box) | Low | Order is renumbered sequentially from the Repeater's natural item order on Save (`mutateFormDataBeforeSave`). True drag-and-drop is deferred to a follow-up; current Repeater up/down controls are sufficient. |
| `type` switch behavior — `is_correct` flags not auto-reset, teacher may ship a question with stale correct marks | Low | `afterStateUpdated` fires a Filament Notification warning to review `is_correct` flags; does not auto-clear or block. Validation still enforces ≥1 correct per question at submit. |
| Deferred grading logic — no auto-grading or strict-MCQ enforcement in the builder | Low (by design) | Intentional deferral documented in proposal, spec, and README; builder only collects `is_correct`, enforcing ≥1 correct per question so exams remain usable for the future engine. The all-correct-AND-no-incorrect rule is the student module's responsibility. |
| `max_score` override drift — teacher sets max_score far from sum of points, confusing future grading | Med | Helper text documents "Defaults to sum of question points"; override allowed. Non-blocking warning on submit when `max_score < sum(points)`. |
| Nested Repeaters performance on large exams (many questions × many options) | Low | Repeater is server-rendered per item; acceptable for authoring scale (tens of questions). Chunking/real-time field limits are a follow-up if exams grow large. |
| No `is_published` state — all exams are drafts; teacher could "preview" an exam that is not yet deliverable | Low | Intended deferral; publish/lock lifecycle belongs to the engine change. Preview action is view-only, never a delivery path. |

## Rollback Plan

- Run `php artisan migrate:rollback` to drop `answer_options`, then `questions`, then `exams` (reverse order).
- Delete `ExamResource`, its `Pages/` stubs, and the three new models.
- Delete `QuestionType` enum.
- Revert `README.md` (remove the exams section) and delete the three Pest test files.
- Remove `exams()` hasMany from `SchoolClass`.
- No data outside the three new tables is affected: classes, materials, and the invitation flow remain intact. No public-facing route or view was modified, so no student/public behavior changes.

## Dependencies

- `platform-scaffold` (Laravel 13 / Filament v5 / Livewire / Pest stack installed and weaponized).
- `admin-teacher-management` (TEACHER role and auth).
- `teacher-class-management` (`SchoolClass` with `teacher_id`; exams scope to classes owned by `Auth::id()`; reuses the `getEloquentQuery()` + `whereHas('classroom', …)` pattern).
- `teacher-study-material-management` (established Filament v5 conditional-form + `live()`/`afterStateUpdated` conventions to follow).
- Filament v5 Repeater + sub-Repeater support (already installed, v5.6.8).

## Future Capabilities Enabled

- `student-exams`: `student_attempts` (PRD §5.8) + `student_answers` (§5.9) tables, exam-taking Livewire UI with a server-validated countdown timer, auto-submit on timeout (PRD §4.1), and single-attempt enforcement.
- Grading engine: strict MCQ rule (all correct AND no incorrect = full points; partial/zero logic per product decision), `score_obtained` persistence, and the "Tu calificación es: X / Y" instant result (PRD §4.1).
- `is_published` / exam-lock lifecycle: draft → published → locked states plus optional `start_at`/`end_at` scheduling.
- Teacher reports (PDF + Excel): evaluation-plan and grade-sheet exports via `barryvdh/laravel-dompdf` + `maatwebsite/excel`, which require attempts data to exist first.
- Attempt review: teacher UI to inspect each student's attempt and per-question correctness.
- Question pool / randomization and CSV/JSON bulk import of questions.
- Drag-and-drop question reorder (true Filament v5 sortable Repeater or a workaround).

## Success Criteria

- [ ] A teacher can create an exam with ≥1 question, each question with ≥2 options, in one Filament form with nested Repeaters.
- [ ] `max_score` defaults to the sum of question points when left blank and is overridable; out-of-range sums surface a non-blocking warning.
- [ ] Switching a question's `type` fires a Filament Notification to review `is_correct` flags without auto-clearing or blocking save; ≥1 correct option per question is enforced.
- [ ] Teacher A cannot list, edit, delete, or directly access Teacher B's exams (query scope; direct edit URL → not found).
- [ ] Deleting an exam cascades to its questions and options; deleting a class cascades to its exams at the DB level.
- [ ] The per-question `order` column is renumbered sequentially on save from the Repeater's natural order.
- [ ] "Preview as student" action renders the exam's questions and options on the Edit page.
- [ ] Pest tests pass for resource behavior and model relationships/casts.