# exam-data-model Specification

## Purpose

Persistent data layer: three new tables (`exams`, `questions`, `answer_options`) with cascade-delete FKs, corresponding Eloquent models with relationships and casts, a `QuestionType` backed enum, and an additive `SchoolClass::exams()` relationship. Structural contract for `teacher-exam-management` and future grading modules.

## Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| 1 | Database Schema | `exams`: id, class_id FK→classes.id onDelete('cascade'), title VARCHAR(255), description TEXT nullable, duration_minutes INTEGER, max_score INTEGER, timestamps. `questions`: id, exam_id FK→exams.id onDelete('cascade'), text TEXT, type ENUM(SINGLE,MULTIPLE) default SINGLE, points INTEGER default 1, order INTEGER default 0, timestamps; index (exam_id, order). `answer_options`: id, question_id FK→questions.id onDelete('cascade'), text TEXT, is_correct BOOLEAN default false, timestamps. |
| 2 | QuestionType Enum | PHP 8.1+ backed enum `QuestionType: string`. Cases: `Single='SINGLE'`, `Multiple='MULTIPLE'`. Methods: `getLabel()`, `getColor()`, `getIcon()` (mirrors `StudyMaterialType`). |
| 3 | Model Relationships | Exam→classroom (belongsTo SchoolClass), Exam→questions (hasMany), Question→exam (belongsTo), Question→options (hasMany AnswerOption), AnswerOption→question (belongsTo), SchoolClass→exams (hasMany, additive). All models use `#[Fillable]`. |
| 4 | Model Casts | Question.type → `QuestionType` enum cast. AnswerOption.is_correct → boolean cast. Exam.duration_minutes, Exam.max_score → integer native casts. |
| 5 | Question Order Column | questions.order INTEGER default 0. Renumbered sequentially on save by ExamResource. Index (exam_id, order) supports ordered queries. |

### Scenario: Migrations create all tables with cascade FKs
- GIVEN fresh database
- WHEN `php artisan migrate` runs
- THEN `exams`, `questions`, `answer_options` tables exist with all columns and types
- AND deleting an exam row cascades to its questions and options at DB level
- AND a composite index on `questions(exam_id, order)` exists

### Scenario: QuestionType enum cases and helpers
- GIVEN `QuestionType::Single`
- WHEN `->value` → THEN `'SINGLE'`; WHEN `->getLabel()` → THEN human-readable label
- GIVEN `QuestionType::Multiple`
- WHEN `->getColor()` and `->getIcon()` → THEN both return non-null strings

### Scenario: Model relationships resolve correctly
- GIVEN exam with class and 3 questions; question with 4 options
- WHEN `$exam->classroom` → THEN SchoolClass instance returned
- WHEN `$exam->questions` → THEN Collection of 3 Questions
- WHEN `$question->exam` and `$question->options` → THEN parent Exam and 4 AnswerOptions
- WHEN `$class->exams` → THEN Collection of 2 Exams (additive, no behavior change)

### Scenario: Model casts transform database values
- GIVEN question with `type='SINGLE'` in DB → WHEN retrieved → THEN `$question->type` is `QuestionType::Single`
- GIVEN option with `is_correct=1` in DB → WHEN retrieved → THEN `$option->is_correct` is PHP `true`

### Scenario: Order column defaults to 0
- GIVEN question created without explicit order
- WHEN persisted → THEN order is 0
- WHEN ExamResource saves → THEN order renumbered sequentially
