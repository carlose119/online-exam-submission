# admin-teacher-management Specification

## Purpose

Admin CRUD over Teacher accounts via Filament `TeacherResource`: list, create, edit, suspend (toggle via `suspended_at`), delete, optional temp-password, unique email, mass-assignment guard, hashed passwords. Uses `users` table with `role = 'TEACHER'`.

## Requirements

### Requirement: Teacher CRUD Resource

The system MUST provide a Filament `TeacherResource` scoped to `role = 'TEACHER'` and listed in the admin panel navigation. It MUST support list, create, edit, and delete.

#### Scenario: CRUD life cycle

- GIVEN an admin in the panel
- WHEN creating a teacher with valid name, unique email, and password
- THEN the teacher appears in the list with correct details
- AND editing the teacher updates persisted data
- AND deleting the teacher removes the row and it disappears from list

### Requirement: Teacher Account Suspension

The system MUST provide a suspend toggle that sets `suspended_at` to now when on and clears to NULL when off. Suspended teachers MUST be denied authentication.

#### Scenario: Suspend and reactivate

- GIVEN an active teacher (suspended_at = NULL)
- WHEN admin toggles suspension on
- THEN `suspended_at` is set and teacher login is rejected
- WHEN admin toggles suspension off
- THEN `suspended_at` clears to NULL and teacher can authenticate

### Requirement: Temporary Password Generation

The system MAY offer a temp-password action on create. When triggered, it MUST generate a random password, hash it for storage, and display plain text to the admin exactly once.

#### Scenario: Generate temp password on create

- GIVEN admin on Teacher create form
- WHEN temp-password is triggered and form submitted
- THEN password is stored hashed and plain text shown in a success message

### Requirement: Unique Email Enforcement

The system MUST reject create/update when the submitted email case-insensitively matches an existing `users.email`. Rejection MUST produce a field-level validation error.

#### Scenario: Duplicate email rejected

- GIVEN teacher `prof@example.com` exists
- WHEN admin submits create with same email
- THEN form rejected with unique email error on the field
- AND same rejection occurs on update when changing another teacher's email to `prof@example.com`

### Requirement: Mass-Assignment Protection

The `User` model MUST use `$fillable` to restrict bulk-assignable attributes. Non-listed attributes MUST NOT be settable via mass assignment.

#### Scenario: Non-fillable attributes blocked

- GIVEN `User::$fillable` lists only `name`, `email`, `password`, `role`, `suspended_at`
- WHEN an attacker sends a request setting `role = 'ADMIN'` via mass assignment on a teacher form
- THEN the attribute is guarded (rejected or ignored)

### Requirement: Password Hashing

Passwords MUST be hashed with Laravel's `Hash` facade before persistence. The `password` column MUST hold a bcrypt hash, never plain text.

#### Scenario: Password stored as hash

- GIVEN admin submits password `secret123` on teacher create
- WHEN `User` is persisted
- THEN `password` column contains a bcrypt hash, not the plain-text value
