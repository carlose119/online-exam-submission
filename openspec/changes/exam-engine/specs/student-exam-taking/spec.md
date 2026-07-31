# student-exam-taking Specification

## Purpose

Authenticated STUDENT exam flow: start (403 if taken or not subscribed), one-question-at-a-time Livewire wizard (radio/checkbox), strict server-enforced countdown timer with auto-submit, idempotent answer saving, submit + grade via ExamGradingService, and "X / Y" result page. Dashboard extended with "Exámenes disponibles" (untaken) and "Exámenes completados" (with scores) sections.

## Requirements

### Requirement: Start Exam

`GET /examenes/{exam}/intentar` MUST: require auth + role:STUDENT; require subscription via class_user pivot (403 otherwise); create StudentAttempt (started_at=now(), finished_at=null) and redirect to take. If attempt already exists for this student+exam, MUST abort 403.

#### Scenario: Subscribed student starts exam

- GIVEN authenticated STUDENT subscribed to the exam's class
- WHEN GET /examenes/1/intentar
- THEN StudentAttempt created with started_at=now(); redirected to /examenes/{attempt}/tomar

#### Scenario: Already-taken exam → 403

- GIVEN student already has a StudentAttempt for this exam
- WHEN GET /examenes/1/intentar
- THEN HTTP 403; no second attempt created

#### Scenario: Not subscribed → 403

- GIVEN authenticated STUDENT NOT subscribed to the exam's class
- WHEN GET /examenes/{exam}/intentar
- THEN HTTP 403

### Requirement: Take Exam (Wizard)

`GET /examenes/{attempt}/tomar` MUST: require auth + role:STUDENT + subscription (403 otherwise); render the first unanswered question (or first if none answered); compute countdown server-side as `started_at + exam.duration_minutes - now()`. Timer expired → auto-submit and redirect to result. Unauthenticated → redirect to /login.

#### Scenario: Take shows first question with timer

- GIVEN fresh attempt with no answers
- WHEN GET /examenes/1/tomar
- THEN first question rendered (radio for SINGLE, checkboxes for MULTIPLE); countdown displayed

#### Scenario: Take resumes from last unanswered question

- GIVEN attempt with questions 1+2 answered, question 3 unanswered
- WHEN student returns to GET /examenes/1/tomar
- THEN question 3 is shown

#### Scenario: Non-STUDENT denied → 403

- GIVEN authenticated TEACHER
- WHEN GET /examenes/1/tomar
- THEN HTTP 403

#### Scenario: Guest redirected to login

- GIVEN no session
- WHEN GET /examenes/1/tomar
- THEN HTTP 302 to /login

### Requirement: Answer Question

`POST /examenes/{attempt}/responder/{question}` MUST idempotently save selected option IDs via updateOrCreate on (student_attempt_id, question_id, answer_option_id). MUST advance to next question, or show "Finalizar" on the last question. Re-answering updates existing selections.

#### Scenario: Answer saves and advances

- GIVEN attempt on question 1 of 3
- WHEN POST /examenes/1/responder/1 with selected options
- THEN answer rows saved; redirected to question 2

#### Scenario: Last question shows "Finalizar"

- GIVEN attempt on question 3 of 3
- WHEN POST /examenes/1/responder/3
- THEN answer saved; "Finalizar" button appears

#### Scenario: Re-answering updates existing selection idempotently

- GIVEN existing answer for question 1 option A
- WHEN POST with option B for same question
- THEN option A replaced by B; no duplicate rows

### Requirement: Submit, Grade, and Result

`POST /examenes/{attempt}/finalizar` MUST call `ExamGradingService::gradeAttempt`, persist score_obtained and finished_at, redirect to result. `GET /examenes/{attempt}/resultado` MUST show "X / Y" (score_obtained / exam.max_score) and MUST only be accessible when finished_at is set (otherwise redirect to take).

#### Scenario: Submit grades and redirects

- GIVEN in-progress attempt with answers
- WHEN POST /examenes/1/finalizar
- THEN gradeAttempt computes score; score_obtained and finished_at saved; redirected to result

#### Scenario: Result page shows "X / Y"

- GIVEN graded attempt with score_obtained=10, exam.max_score=15
- WHEN GET /examenes/1/resultado
- THEN page displays "10 / 15"

#### Scenario: Ungraded attempt redirects to take

- GIVEN attempt with finished_at=null
- WHEN GET /examenes/1/resultado
- THEN redirected to /examenes/1/tomar

### Requirement: Server-Side Timer Enforcement

On every take/answer request, if `now() > started_at + exam.duration_minutes`, the system MUST auto-submit (grade + set finished_at) and redirect to result. This MUST work even when the student closed the browser mid-exam and returns later.

#### Scenario: Timer expired → auto-submit on take

- GIVEN attempt with started_at 60min ago, duration_minutes=30
- WHEN GET /examenes/1/tomar
- THEN auto-submit fires; score set; redirected to result

#### Scenario: Browser-closed mid-exam → auto-submit on resume

- GIVEN student closed browser 2 hours ago; timer was 30min
- WHEN student returns to GET /examenes/1/tomar
- THEN auto-submit fires immediately; student sees result page

### Requirement: Dashboard Extension

The student dashboard MUST display: "Exámenes disponibles" (exams from subscribed classes without a StudentAttempt for the student, each with "Iniciar examen" link) and "Exámenes completados" (exams with a StudentAttempt, showing "X / Y" score).

#### Scenario: Available exams listed with start link

- GIVEN student subscribed to "Math" with exam "Quiz 1" (no attempt)
- WHEN /dashboard loads
- THEN "Exámenes disponibles" shows "Quiz 1" with "Iniciar examen" link to /examenes/{exam}/intentar

#### Scenario: Completed exams listed with scores

- GIVEN student has graded attempt for "Quiz 1" with score 10/15
- WHEN /dashboard loads
- THEN "Exámenes completados" shows "Quiz 1" with "10 / 15"
