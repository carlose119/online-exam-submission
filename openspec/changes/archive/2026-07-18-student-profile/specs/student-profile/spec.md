# student-profile Specification

## Purpose

Read-only student profile page at `/profile` showing user identity (name, email, role) and subscribed classes with per-class counts and joined_at timestamps. Surfaced from the dashboard via a "Mi perfil" link.

## Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| 1 | Profile Page Access Control | `GET /profile` named `profile.show` MUST use `auth` + `role:STUDENT` middleware. Students → 200. Non-students (TEACHER, ADMIN) → 403. Guests → redirect to `/login`. |
| 2 | User Identity Display | Profile MUST display authenticated student's `name`, `email`, and `role` as a read-only header. Role MUST render as "STUDENT" badge. |
| 3 | Subscribed Classes Display | MUST list subscribed classes as cards via `User::subscribedClasses()` with `withCount(['studyMaterials','exams','meetings'])` and `with('teacher')`, ordered by `class_user.created_at DESC`. Each card MUST show: title, teacher.name, joined_at (`diffForHumans()` + `format('M j, Y')`), and three count badges. |
| 4 | Empty State | When zero subscriptions, MUST display "Aún no te has unido a ninguna clase. Pide un link de invitación a tu teacher." with empty-state icon. No cards rendered. |
| 5 | Read-Only Enforcement | Profile MUST NOT include editable fields, password change form, unjoin button, exam history, or meeting history. All explicitly deferred. |
| 6 | Pest Test Coverage | `tests/Feature/StudentProfileTest.php` MUST cover 7+ scenarios: student 200, teacher 403, admin 403, guest redirect, user data accuracy, classes with counts/ordering, and empty state. |

### Scenario: Student accesses profile successfully

- GIVEN an authenticated STUDENT
- WHEN they visit `/profile`
- THEN the page renders with HTTP 200 and displays name, email, role, and subscribed classes

### Scenario: Teacher receives 403

- GIVEN an authenticated TEACHER
- WHEN they visit `/profile`
- THEN the server responds with HTTP 403

### Scenario: Admin receives 403

- GIVEN an authenticated ADMIN
- WHEN they visit `/profile`
- THEN the server responds with HTTP 403

### Scenario: Guest redirected to login

- GIVEN an unauthenticated visitor
- WHEN they visit `/profile`
- THEN the server redirects to `/login`

### Scenario: Profile displays student name and email

- GIVEN authenticated STUDENT "María García" with email "maria@example.com"
- WHEN `/profile` loads
- THEN "María García" and "maria@example.com" are displayed in the identity header

### Scenario: Profile displays role badge

- GIVEN authenticated STUDENT
- WHEN `/profile` loads
- THEN a badge or label indicating "STUDENT" role is displayed

### Scenario: Profile lists subscribed classes with counts and teacher

- GIVEN authenticated STUDENT subscribed to "Math 101" (teacher: "Prof. López", 3 materials, 2 exams, 1 meeting)
- WHEN `/profile` loads
- THEN "Math 101" card shows title, teacher "Prof. López", joined_at date, "3 materials", "2 exams", and "1 meeting" badges

### Scenario: Subscribed classes ordered by most recent first

- GIVEN student joined "Physics" after "Chemistry"
- WHEN `/profile` loads
- THEN "Physics" appears before "Chemistry" in the card list

### Scenario: Joined date in human-readable and calendar formats

- GIVEN student joined a class 2 weeks ago
- WHEN `/profile` loads
- THEN joined_at displays a relative format (e.g., "2 weeks ago") and a calendar date (e.g., "Jan 5, 2026")

### Scenario: Empty state when no subscriptions

- GIVEN authenticated STUDENT with zero subscribed classes
- WHEN `/profile` loads
- THEN "Aún no te has unido a ninguna clase. Pide un link de invitación a tu teacher." is displayed with an empty-state icon; no class cards rendered

### Scenario: No deferred features present

- GIVEN authenticated STUDENT on `/profile`
- WHEN the page renders
- THEN no exam history, meeting history, password change form, unjoin button, or editable fields are present

### Scenario: Pest test covers all behaviors

- GIVEN `tests/Feature/StudentProfileTest.php`
- WHEN `php artisan test --filter StudentProfileTest` runs
- THEN at least seven test methods pass covering access control, data display, ordering, and empty state
