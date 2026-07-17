# student-class-subscription Specification

## Purpose

Authenticated students subscribe to classes via the public join page. The `class_user` pivot per PRD §5.3 persists subscriptions idempotently. The `/dashboard` lists subscribed classes as cards with an empty state.

## Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| 1 | class_user Pivot Table | id, class_id FK→classes onDelete('cascade'), user_id FK→users onDelete('cascade'), timestamps, UNIQUE(class_id, user_id). |
| 2 | Guest Join Page | Guest MUST see login link to `/login?redirect=/clase/unirse/{code}` (replaces `/admin/login`). Post-login returns to join page. |
| 3 | Authenticated Join | Auth student MUST see "Unirse a clase" button (POST), replacing TBD. Click creates row via `firstOrCreate`, redirects to `/dashboard` with success flash. No auto-subscribe. |
| 4 | Idempotent Join | Duplicate join MUST be a graceful no-op: no duplicate row, no error, same redirect. |
| 5 | Join Edge Cases | POST with nonexistent code MUST return 404. POST without auth MUST return 302 to `/login`. |
| 6 | Model Relationships | `SchoolClass::students()` and `User::subscribedClasses()` MUST use belongsToMany with `withTimestamps()`. |
| 7 | Dashboard | `/dashboard` MUST require `auth` + `role:STUDENT`. Lists subscribed classes as cards (title, description, #materials, #exams). Empty state when zero subscriptions. Non-STUDENT denied. |

### Scenario: Pivot table schema and cascade

- GIVEN migration run
- WHEN `class_user` table is created
- THEN columns id, class_id, user_id, timestamps exist with cascade-delete FKs and UNIQUE(class_id, user_id)

### Scenario: Guest sees login link with redirect

- GIVEN unauthenticated visitor at `/clase/unirse/abc12345`
- WHEN the page renders
- THEN "Log in to join" links to `/login?redirect=/clase/unirse/abc12345`; after login, user returns to join page

### Scenario: Auth student sees join button, not auto-subscribed

- GIVEN authenticated STUDENT at `/clase/unirse/abc12345`
- WHEN the page renders
- THEN "Unirse a clase" button appears (no TBD); student is NOT yet subscribed

### Scenario: Join action creates subscription

- GIVEN authenticated STUDENT clicks "Unirse a clase" on `/clase/unirse/abc12345`
- WHEN POST fires
- THEN `class_user` row created; redirected to `/dashboard` with success flash

### Scenario: Duplicate join is idempotent

- GIVEN student already subscribed to class "abc12345"
- WHEN student clicks "Unirse a clase" again
- THEN no duplicate row; redirected to `/dashboard` with success flash (no error)

### Scenario: Join with nonexistent code returns 404

- GIVEN authenticated STUDENT
- WHEN POST to `/clase/unirse/nonexistent/join`
- THEN HTTP 404 returned

### Scenario: Join without auth redirects to login

- GIVEN no session
- WHEN POST to `/clase/unirse/abc12345/join`
- THEN HTTP 302 redirects to `/login`

### Scenario: Relationships resolve with timestamps

- GIVEN class "Math 101" with 3 subscribed students
- WHEN `$class->students` and `$user->subscribedClasses` are called
- THEN correct collections returned; `created_at`/`updated_at` populated on pivot rows

### Scenario: Cascade delete removes subscriptions

- GIVEN class with 5 subscriptions and user "Alice" with 2 subscriptions
- WHEN class or user is deleted
- THEN all related `class_user` rows cascade-delete

### Scenario: DB unique constraint blocks duplicate

- GIVEN existing (class_id=1, user_id=2) row
- WHEN duplicate insert bypasses Eloquent
- THEN database rejects with unique constraint violation

### Scenario: Dashboard shows subscribed classes as cards

- GIVEN authenticated STUDENT subscribed to "Math" and "Physics"
- WHEN `/dashboard` loads
- THEN cards for both classes appear with title, description, #materials, and #exams

### Scenario: Dashboard empty state

- GIVEN authenticated STUDENT with zero subscriptions
- WHEN `/dashboard` loads
- THEN "You haven't joined any classes yet. Use an invitation link from your teacher to get started." is displayed

### Scenario: Non-STUDENT denied from dashboard

- GIVEN authenticated TEACHER
- WHEN navigating to `/dashboard`
- THEN access denied via `role:STUDENT` middleware (403 or redirect)
