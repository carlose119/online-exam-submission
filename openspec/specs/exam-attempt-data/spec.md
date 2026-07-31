# exam-attempt-data Specification

## Purpose

Persistent attempt/answer data model per PRD §5.8/§5.9. `student_attempts` enforces 1 attempt per student per exam via UNIQUE constraint. `student_answers` records one row per selected option per question (MULTIPLE questions require multi-row) via UNIQUE on 3 columns. Cascade-delete FKs and Eloquent models with relationships and native casts.

## Requirements

### Requirement: student_attempts Table Schema

Columns: id, student_id FK→users onDelete('cascade'), exam_id FK→exams onDelete('cascade'), score_obtained DECIMAL(8,2) nullable, started_at timestamp, finished_at timestamp nullable, timestamps. DB MUST enforce UNIQUE(student_id, exam_id).

#### Scenario: Table created with correct columns

- GIVEN migration run
- THEN student_attempts exists with id, student_id, exam_id, score_obtained, started_at, finished_at, created_at, updated_at

#### Scenario: UNIQUE constraint blocks duplicate attempt

- GIVEN existing attempt for (student_id=1, exam_id=1)
- WHEN insert for same (student_id=1, exam_id=1)
- THEN DB rejects with unique constraint violation

#### Scenario: Cascade delete on both FKs

- GIVEN student with 3 attempts; exam with 2 attempts
- WHEN student is deleted → THEN all 3 attempts cascade-delete
- WHEN exam is deleted → THEN both attempts cascade-delete

### Requirement: student_answers Table Schema

Columns: id, student_attempt_id FK→student_attempts onDelete('cascade'), question_id FK→questions onDelete('cascade'), answer_option_id FK→answer_options onDelete('cascade'), timestamps. DB MUST enforce UNIQUE(student_attempt_id, question_id, answer_option_id) — 3-column constraint allows multiple selections per MULTIPLE question.

#### Scenario: Table created with correct columns

- GIVEN migration run
- THEN student_answers exists with id, student_attempt_id, question_id, answer_option_id, created_at, updated_at

#### Scenario: 3-column UNIQUE allows multiple options per question

- GIVEN an attempt answering a MULTIPLE question
- WHEN student selects options A and B for the same question
- THEN two rows inserted (attempt+question+A, attempt+question+B)
- AND inserting the same option again is rejected (duplicate)

#### Scenario: Cascade delete on all FKs

- GIVEN 5 student_answers rows
- WHEN parent attempt/question/option is deleted
- THEN related answer rows cascade-delete

### Requirement: Eloquent Model Relationships

`StudentAttempt` MUST define: student() belongsTo User(student_id), exam() belongsTo Exam, answers() hasMany StudentAnswer. `StudentAnswer` MUST define: attempt() belongsTo StudentAttempt, question() belongsTo Question, option() belongsTo AnswerOption(answer_option_id).

#### Scenario: StudentAttempt relationships resolve

- GIVEN StudentAttempt for student Alice, exam "Quiz 1", with 2 answers
- WHEN $attempt->student, $attempt->exam, $attempt->answers called
- THEN User Alice, Exam "Quiz 1", Collection of 2 StudentAnswers returned

#### Scenario: StudentAnswer relationships resolve

- GIVEN StudentAnswer linked to attempt 1, question 3, option B
- WHEN $answer->attempt, $answer->question, $answer->option called
- THEN correct StudentAttempt, Question, AnswerOption returned

### Requirement: Cross-Exam Independence

A student MAY have attempts for multiple different exams. The UNIQUE constraint MUST NOT prevent attempts on distinct exams.

#### Scenario: Multiple attempts across different exams

- GIVEN student with attempt for exam 1
- WHEN student starts exam 2
- THEN new attempt created without constraint violation
