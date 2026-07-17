# Tasks: Teacher Exams (Builder and Question Types)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 675–800 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (enum + migrations + models + SchoolClass + ExamResource + pages) → PR 2 (tests + README) |
| Delivery strategy | ask-always |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Data layer + ExamResource (enum, 3 migrations, 3 models, SchoolClass, resource, 3 pages) | PR 1 | `php artisan migrate:fresh && php artisan route:list --path=admin/exams` | `php artisan serve` → open `/admin/exams` (Filament panel), create exam with questions+options | `php artisan migrate:rollback --step=3`, delete `app/Enums/QuestionType.php`, `app/Models/{Exam,Question,AnswerOption}.php`, `app/Filament/Resources/ExamResource{,.php,/Pages/}`, revert `SchoolClass.php` |
| 2 | Tests + documentation (3 test files, README) | PR 2 | `vendor/bin/pest tests/Feature/ExamResourceTest.php tests/Feature/QuestionModelTest.php tests/Feature/AnswerOptionModelTest.php` | N/A — test-only unit; no runtime deployable surface | Delete 3 test files and revert README section |

## Phase 1: Data Layer

- [x] 1.1 Create `app/Enums/QuestionType.php` — backed enum `Single='SINGLE'`, `Multiple='MULTIPLE'` with `getLabel()`, `getColor()`, `getIcon()` (mirror `StudyMaterialType`). **Verify**: `php -r "require 'vendor/autoload.php'; echo \App\Enums\QuestionType::Single->getLabel();"` prints label.
- [x] 1.2 Create `database/migrations/*_create_exams_table.php` — `id`, `class_id` FK→classes `cascadeOnDelete`, `title` VARCHAR(255), `description` TEXT nullable, `duration_minutes` INTEGER, `max_score` INTEGER, timestamps. **Verify**: `php artisan migrate` creates table; `Test-Path database/migrations/*_create_exams_table.php`.
- [x] 1.3 Create `database/migrations/*_create_questions_table.php` — `id`, `exam_id` FK→exams `cascadeOnDelete`, `text` TEXT, `type` ENUM('SINGLE','MULTIPLE') default SINGLE, `points` INTEGER default 1, `order` INTEGER default 0, timestamps; `index(['exam_id','order'])`. **Verify**: `php artisan migrate`; `php artisan db:table questions` shows columns.
- [x] 1.4 Create `database/migrations/*_create_answer_options_table.php` — `id`, `question_id` FK→questions `cascadeOnDelete`, `text` TEXT, `is_correct` BOOLEAN default false, timestamps. **Verify**: `php artisan migrate`; `php artisan db:table answer_options` shows columns.
- [x] 1.5 Create `app/Models/Exam.php` — `#[Fillable(['class_id','title','description','duration_minutes','max_score'])]`, `casts()` for `duration_minutes`/`max_score` integer, `classroom()` belongsTo `SchoolClass`, `questions()` hasMany `Question`. **Verify**: `php artisan tinker --execute="echo get_class((new App\Models\Exam())->questions());"` shows HasMany.
- [x] 1.6 Create `app/Models/Question.php` — `#[Fillable(['exam_id','text','type','points','order'])]`, `casts()`: `type`→`QuestionType::class`, `exam()` belongsTo, `options()` hasMany `AnswerOption` ordered by `id` ASC. **Verify**: `php artisan tinker --execute="\$q = new App\Models\Question(); echo \$q->getCasts()['type'];"`.
- [x] 1.7 Create `app/Models/AnswerOption.php` — `#[Fillable(['question_id','text','is_correct'])]`, `casts()`: `is_correct` boolean, `question()` belongsTo. **Verify**: `php artisan tinker --execute="echo (new App\Models\AnswerOption())->getCasts()['is_correct'];"`.
- [x] 1.8 Add `exams(): HasMany` to `app/Models/SchoolClass.php` (after `studyMaterials()`). **Verify**: `php artisan tinker --execute="echo get_class((new App\Models\SchoolClass())->exams());"` shows HasMany.

## Phase 2: Filament ExamResource

- [x] 2.1 Create `app/Filament/Resources/ExamResource.php` — form with `Select('class_id')` (teacher scope), exam details section, questions `Repeater` (`minItems(1)`, `text` Textarea, `type` Select with `live()`+`afterStateUpdated`→`Notification::warning()`, `points`, options sub-Repeater `minItems(2)` with `text`+`is_correct`), `max_score` helper text. **Verify**: `php artisan route:list --path=admin/exams` lists create route; visit `/admin/exams/create` renders form.
- [x] 2.2 Add table + query scope to `ExamResource` — `getEloquentQuery()` scoped via `whereHas('classroom', fn($q)=>$q->where('teacher_id', Auth::id()))`, table columns (`title` sortable/searchable, `classroom.title`, `duration_minutes` Badge, `max_score` Badge, question count via `withCount`, `created_at`), `defaultSort('created_at','desc')`, Edit/Delete actions. **Verify**: `php artisan tinker --execute="dump(App\Filament\Resources\ExamResource::getEloquentQuery()->toSql());"` shows `where exists` clause.
- [x] 2.3 Create `app/Filament/Resources/ExamResource/Pages/ListExams.php` — `ListRecords` stub extending `ExamResource`. **Verify**: `php artisan route:list --path=admin/exams` shows index route.
- [x] 2.4 Create `app/Filament/Resources/ExamResource/Pages/CreateExam.php` — `CreateRecord` with `mutateFormDataBeforeCreate`: default `max_score` to sum question points when blank, set `order` from Repeater position, validate ≥1 correct option per question. **Verify**: create exam via UI with 2 questions (5+10 pts), blank max_score → DB shows max_score=15.
- [x] 2.5 Create `app/Filament/Resources/ExamResource/Pages/EditExam.php` — `EditRecord` with `mutateFormDataBeforeSave` (renumber `order` 1,2,3…), preview header action (modal with JSON dump). **Verify**: edit exam, reorder questions in Repeater, save → DB order columns are sequential.

## Phase 3: Tests

- [ ] 3.1 Create `tests/Feature/ExamResourceTest.php` — CRUD (create exam→list→edit), type-switch notification preserves flags, query scope cross-teacher isolation, minItems validation, cascade delete, order renumbering, class Select scoping. **Verify**: `vendor/bin/pest tests/Feature/ExamResourceTest.php` — all pass.
- [ ] 3.2 Create `tests/Feature/QuestionModelTest.php` — relationship resolution (`exam()`, `options()`), `QuestionType` enum cast round-trip, order column default. **Verify**: `vendor/bin/pest tests/Feature/QuestionModelTest.php` — all pass.
- [ ] 3.3 Create `tests/Feature/AnswerOptionModelTest.php` — relationship resolution (`question()`), `is_correct` boolean cast round-trip. **Verify**: `vendor/bin/pest tests/Feature/AnswerOptionModelTest.php` — all pass.

## Phase 4: Documentation

- [ ] 4.1 Add "Teacher exams: builder and question types" section to `README.md` after the "Teacher materials" section. **Verify**: `Select-String -Path README.md -Pattern 'Teacher exams'` returns match.

## Phase 5: Final Verification

- [ ] 5.1 Run full test suite: `vendor/bin/pest` — all existing (46+) + new tests pass. **Verify**: exit code 0, no failures.
- [ ] 5.2 Smoke-test Filament routes: `php artisan route:list --path=admin/exams` shows index, create, edit. **Verify**: 3 routes listed.
