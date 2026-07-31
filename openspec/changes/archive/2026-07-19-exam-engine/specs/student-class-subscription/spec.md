# Delta for student-class-subscription

## MODIFIED Requirements

### Requirement: Dashboard (Requirement #7)

`/dashboard` MUST require `auth` + `role:STUDENT`. Lists subscribed classes as cards (title, description, #materials, #exams). MUST display "Exámenes disponibles" section: exams from subscribed classes where no StudentAttempt exists for the authenticated student, each with an "Iniciar examen" link to `student.exam.start`. MUST display "Exámenes completados" section: exams with a StudentAttempt for the student, showing "X / Y" where X=score_obtained and Y=exam.max_score. Empty state when zero subscriptions. Non-STUDENT denied.

(Previously: Dashboard only listed subscribed classes as cards with an empty state. No exam sections.)

#### Scenario: Dashboard shows subscribed classes as cards

- GIVEN authenticated STUDENT subscribed to "Math" and "Physics"
- WHEN `/dashboard` loads
- THEN cards for both classes appear with title, description, #materials, and #exams

#### Scenario: Dashboard empty state

- GIVEN authenticated STUDENT with zero subscriptions
- WHEN `/dashboard` loads
- THEN "You haven't joined any classes yet. Use an invitation link from your teacher to get started." is displayed

#### Scenario: Non-STUDENT denied from dashboard

- GIVEN authenticated TEACHER
- WHEN navigating to `/dashboard`
- THEN access denied via `role:STUDENT` middleware (403 or redirect)

#### Scenario: Dashboard shows available exams

- GIVEN authenticated STUDENT subscribed to "Math" class that has exam "Quiz 1" with no StudentAttempt
- WHEN `/dashboard` loads
- THEN "Exámenes disponibles" section lists "Quiz 1" with "Iniciar examen" link

#### Scenario: Dashboard shows completed exams with scores

- GIVEN authenticated STUDENT has graded attempt for "Quiz 1" with score_obtained=10 and exam.max_score=15
- WHEN `/dashboard` loads
- THEN "Exámenes completados" section shows "Quiz 1" with "10 / 15"
