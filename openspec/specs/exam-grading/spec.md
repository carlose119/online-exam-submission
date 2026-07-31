# exam-grading Specification

## Purpose

Strict auto-grading engine per PRD §4.1 via `ExamGradingService::gradeAttempt(StudentAttempt): float`. SINGLE questions: correct option → full points, else 0. MULTIPLE questions: ALL correct AND no incorrect selected → full points, else 0. Total score is sum of per-question points, stored as DECIMAL in score_obtained. Grading is idempotent.

## Requirements

### Requirement: SINGLE Question Grading

For a SINGLE question, the system MUST award question.points when exactly one answer row exists AND its option.is_correct is true. Otherwise MUST award 0.

#### Scenario: Correct answer → full points

- GIVEN a SINGLE question worth 5pts with correct option B
- WHEN student selected option B
- THEN question score = 5

#### Scenario: Incorrect answer → 0

- GIVEN a SINGLE question worth 5pts with correct option B
- WHEN student selected option A (incorrect)
- THEN question score = 0

#### Scenario: No answer (blank) → 0

- GIVEN a SINGLE question worth 5pts
- WHEN student has zero answer rows for this question
- THEN question score = 0

### Requirement: MULTIPLE Question Grading (Strict)

For a MULTIPLE question, the system MUST award question.points only when: every selected option is correct (is_correct=true) AND every correct option for that question is selected AND no incorrect option is selected. Any deviation MUST award 0.

#### Scenario: All correct, no incorrect → full points

- GIVEN a MULTIPLE question worth 5pts; correct options: A, C
- WHEN student selected A and C (exact match)
- THEN question score = 5

#### Scenario: All correct plus one incorrect → 0

- GIVEN a MULTIPLE question worth 5pts; correct options: A, C
- WHEN student selected A, C, and B (B is incorrect)
- THEN question score = 0

#### Scenario: Some correct but not all → 0

- GIVEN a MULTIPLE question worth 5pts; correct options: A, C
- WHEN student selected only A
- THEN question score = 0

#### Scenario: No options selected (blank) → 0

- GIVEN a MULTIPLE question worth 5pts
- WHEN student has zero answer rows for this question
- THEN question score = 0

### Requirement: Aggregate Score and Idempotency

`gradeAttempt` MUST iterate all exam questions, sum per-question scores, store result in score_obtained (DECIMAL), and set finished_at. Calling gradeAttempt on an already-graded attempt MUST return the same score as a no-op.

#### Scenario: Total score is sum of correctly answered question points

- GIVEN exam with 3 questions of 5pts each; student correctly answers 2
- THEN score_obtained = 10

#### Scenario: Idempotent on re-grade

- GIVEN attempt with finished_at already set and score=10
- WHEN gradeAttempt is called again
- THEN same score returned; no duplicate writes; finished_at unchanged

### Requirement: Grading Service Interface

`ExamGradingService::gradeAttempt(StudentAttempt $attempt): float` MUST be the single grading entry point. It MUST NOT have side effects beyond setting score_obtained and finished_at on the attempt.

#### Scenario: Grading invoked on submit sets score and finished_at

- GIVEN an in-progress StudentAttempt with answers
- WHEN gradeAttempt is called
- THEN score_obtained set to computed sum; finished_at set to now; float score returned
