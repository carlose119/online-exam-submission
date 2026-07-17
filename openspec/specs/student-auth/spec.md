# student-auth Specification

## Purpose

Student authentication via Breeze + Livewire stack using the existing `User` model with `role = STUDENT`. Separate from the Filament admin guard. Covers registration, login, logout, password reset scaffolding, and role-gated `/dashboard`.

## Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| 1 | Registration | Student MUST register with name, email, password. Creates `User` with `role = STUDENT`, hashed password via existing cast/mutator. |
| 2 | Unique Email | System MUST reject registration when email already exists in `users`. |
| 3 | Login | Student MUST authenticate with valid email + password via web guard. |
| 4 | Invalid Credentials | System MUST reject login with wrong email or password with a validation error. |
| 5 | Logout | System MUST clear session and redirect to `/` on logout. |
| 6 | Password Reset | Routes MUST exist at `/forgot-password` and `/reset-password/{token}`. Email is NOT sent (no mailer configured — documented limitation). |
| 7 | Admin Panel Denied | Student MUST NOT access `/admin`; Filament CheckRole:ADMIN,TEACHER middleware denies access. |
| 8 | Dashboard Access | Student MUST access `/dashboard` behind `auth` + `role:STUDENT`. |
| 9 | Unauthenticated Redirect | Unauthenticated requests to protected routes MUST redirect to `/login`. |
| 10 | Email Verification Deferred | Verification route is wired but `MustVerifyEmail` is NOT implemented on `User`. Documented limitation. |

### Scenario: Student registers successfully

- GIVEN registration form at `/register`
- WHEN student submits name, unique email, and password
- THEN a `User` with `role = STUDENT` and hashed password is created
- AND student is authenticated and redirected to `/dashboard`

### Scenario: Duplicate email rejected

- GIVEN user `student@example.com` exists
- WHEN new registration uses `student@example.com`
- THEN form is rejected with a field-level validation error

### Scenario: Valid login

- GIVEN a STUDENT user with email `s@x.com` and password `secret`
- WHEN login form is submitted with those credentials
- THEN student is authenticated and redirected to `/dashboard`

### Scenario: Invalid login rejected

- GIVEN a STUDENT user with email `s@x.com`
- WHEN login form is submitted with wrong password
- THEN authentication fails and an error message is displayed

### Scenario: Logout clears session

- GIVEN an authenticated student session
- WHEN student clicks logout
- THEN session is cleared and student is redirected to `/`

### Scenario: Password reset request page loads

- GIVEN the forgot-password page at `/forgot-password`
- WHEN a student submits their registered email
- THEN the route processes the request; no email is actually sent (documented limitation)

### Scenario: Password reset with new password

- GIVEN a valid reset token
- WHEN student submits a new password via `/reset-password/{token}`
- THEN the password is updated in the database

### Scenario: Student blocked from /admin

- GIVEN an authenticated STUDENT session
- WHEN student navigates to `/admin`
- THEN access is denied via Filament CheckRole middleware (redirect or 403)

### Scenario: Student accesses /dashboard

- GIVEN an authenticated STUDENT session
- WHEN student navigates to `/dashboard`
- THEN HTTP 200 is returned; the dashboard view renders

### Scenario: Guest redirected to login

- GIVEN no session
- WHEN guest navigates to `/dashboard` or any protected route
- THEN redirected to `/login`

### Scenario: Email verification is deferred

- GIVEN the verify-email route exists in `routes/auth.php`
- WHEN a new student registers
- THEN `User` does not implement `MustVerifyEmail`; verification is not enforced
