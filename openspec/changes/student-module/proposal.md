# Proposal: Student Module (Auth, Join, and Multi-Class Dashboard)

## Intent (Why)

Changes 1–4 built the teacher side (admin, classes, materials, exams). Students still have no auth, no way to subscribe to a class, and no dashboard. PRD §3.4 requires student-side interfaces; PRD §5.3 defines the `class_user` pivot. The public invitation page (`class-invitation-flow`) already renders class info but its authenticated join affordance is a TBD placeholder that creates no subscription and its login link points to `/admin/login` (wrong audience). This change delivers the first student slice per decision #1: Breeze + Livewire auth, an explicit join button + POST route, the `class_user` pivot, and a multi-class dashboard. Exam taking, the grading engine, and the timer are explicitly deferred.

## What Changes

- Install `laravel/breeze` (dev) with the `livewire` stack via `composer require laravel/breeze --dev && php artisan breeze:install livewire --no-interaction`. Generates `routes/auth.php` (login, register, forgot/reset-password, verify-email, confirm-password), `app/Livewire/Auth/*`, `resources/views/livewire/{auth,layouts,components}`, Breeze's `tests/Feature/Auth/*`, and Tailwind integration.
- New migration `*_create_class_user_table.php` per PRD §5.3: `id`, `class_id` FK→`classes.id` `onDelete('cascade')`, `user_id` FK→`users.id` `onDelete('cascade')`, timestamps, `$table->unique(['class_id','user_id'])`.
- `User::subscribedClasses()` belongsToMany + `SchoolClass::students()` belongsToMany (both via `class_user`, `withTimestamps`).
- Modify `JoinClassController`: keep `show($code)`; add `join(Request,$code)` — auth-required, find-by-`invitation_code` else 404, `firstOrCreate(['class_id'=>$class->id,'user_id'=>Auth::id()],[])` (idempotent), redirect `/dashboard` with success flash.
- Modify `resources/views/class/join.blade.php`: guests → login link `?redirect=/clase/unirse/{code}`; authenticated students → `<form method="POST" action="{{ route('class.join.action',$code) }}">` with a "Unirse a clase" button (replaces the TBD placeholder).
- New routes in `routes/web.php`: POST `/clase/unirse/{invitation_code}/join` named `class.join.action` (behind `auth`); GET `/dashboard` named `dashboard` (behind `['auth','role:STUDENT']`, reusing the variadic `CheckRole`).
- New `app/Livewire/Dashboard.php` + `resources/views/livewire/dashboard.blade.php`: subscribed classes as cards (title, description, #materials, #exams) and an empty state ("You haven't joined any classes yet. Use an invitation link from your teacher to get started.").
- Pest tests: extend `ClassInvitationFlowTest` (join form present for auth students; login link now carries `?redirect`); new `StudentJoinClassTest` (creates pivot row, redirects to dashboard, idempotent on duplicate join, requires auth), `StudentDashboardTest` (requires auth, redirects guests, renders cards + empty state), `ClassUserPivotTest` (unique constraint, cascade delete, both relationships); Breeze's `Auth/{Registration,Login}Test`.
- README: new "Student auth and multi-class subscription" section after the teacher-exams section, documenting the register → login → join → dashboard flow and the deferred items (exam taking, grading engine, timer, reports, email verification, profile/password editing) plus the no-mailer limitation.

## Capabilities

### New Capabilities

- `student-auth`: Student registration, login, forgot/reset password, confirm-password, logout, and a session-protected `/dashboard`. Breeze + Livewire stack separate from the Filament admin guard, reusing the existing `User` model with the `role` enum STUDENT case. Email verification, profile, and password-edit routes are wired as Breeze scaffolding but left disabled/no-op (`User` does NOT implement `MustVerifyEmail`).
- `class-subscription`: Authenticated student subscribes to a class via an explicit "Unirse a clase" button posting to `/clase/unirse/{code}/join`; the `class_user` pivot per PRD §5.3 persists the subscription idempotently (DB-level unique constraint + `firstOrCreate`); the dashboard lists subscribed classes.

### Modified Capabilities

- `class-invitation-flow`: the authenticated TBD placeholder is replaced by an actual join form posting to the new `class.join.action` route; the unauthenticated login link targets `?redirect=/clase/unirse/{code}` (instead of `/admin/login`) so guests land back on the join page after Breeze login.

## Approach

Two coexisting auth stacks: Filament (admin guard at `/admin`) for ADMIN/TEACHER, and Breeze (web guard) for STUDENT. Breeze uses the existing `User` model unchanged — the `role` enum already carries STUDENT, and the password cast/mutator already exist, so no auth-model changes are needed. The `class_user` pivot per PRD §5.3 carries a DB-level unique constraint, so idempotency holds even under a race; the controller uses `firstOrCreate` for a graceful no-op on duplicate joins. The subscription is an explicit "Unirse a clase" button (not auto-subscribe) to keep the act intentional. `CheckRole` is already variadic, so `CheckRole:STUDENT` reuse is zero new middleware — only a `role:STUDENT` alias is registered for the dashboard route group.

## Impact

| Area | Impact | Description |
|------|--------|-------------|
| `composer.json` | Modified | Add `laravel/breeze` dev dependency. |
| `database/migrations/*_create_class_user_table.php` | New | `class_user` pivot per PRD §5.3, unique + cascade. |
| `app/Models/User.php` | Modified | Add `subscribedClasses()` belongsToMany (additive). |
| `app/Models/SchoolClass.php` | Modified | Add `students()` belongsToMany (additive). |
| `app/Http/Controllers/JoinClassController.php` | Modified | Add `join()` POST action; `show()` unchanged. |
| `resources/views/class/join.blade.php` | Modified | Replace TBD with join form; login link carries `?redirect`. |
| `routes/web.php` | Modified | POST `class.join.action` + GET `dashboard`; `routes/auth.php` included by Breeze. |
| `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php` | New | Student dashboard with cards + empty state. |
| `app/Livewire/Auth/*`, `resources/views/livewire/{auth,layouts,components}` | New | Breeze-generated auth stack. |
| `tests/Feature/{StudentJoinClass,StudentDashboard,ClassUserPivot}Test.php` | New | New behavior coverage. |
| `tests/Feature/ClassInvitationFlowTest.php` | Modified | Assert join form + `?redirect` link. |
| `tests/Feature/Auth/{Registration,Login}Test.php` | New | Breeze defaults (kept). |
| `README.md` | Modified | New student section after the exams section. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Breeze + Filament coexistence: Breeze uses the `User` model with the `web` guard; Filament uses its own admin guard. Both share the `User` model but separate guards, so a logged-in student shouldn't reach `/admin` and a Filament admin shouldn't reach `/dashboard` unexpectedly. | Med | Filament panel already gates roles to ADMIN/TEACHER; student `role:STUDENT` middleware gates `/dashboard`. Auth scaffolding is kept separate. Architecture documented in README. Pre-existing `CheckRole` admin middleware is unchanged. |
| No mailer configured (`.env` has no MAIL_MAILER): password reset and email verification routes are wired by Breeze but won't send emails. | Med | Document as a known limitation in README; email verification is left disabled (`User` does NOT implement `MustVerifyEmail`). Password reset UI runs but email delivery is deferred to a follow-up that configures a mailer. |
| `class_user` duplicate subscription under a race / double-submit. | Med | DB-level `$table->unique(['class_id','user_id'])` is the source of truth; controller uses `firstOrCreate([], [])` for a no-op on duplicate so the UX is graceful and idempotent even if the unique index fires. |
| Explicit "Unirse a clase" button vs auto-subscribe — a student who visits the link may expect to be added automatically, and an extra click is friction. | Low | Intentional trade-off (scope decision #3): the explicit button matches the existing page affordance and keeps subscription deliberate; the redirect-after-login returns them straight to the join page so it is one click. Auto-subscribe is explicitly out of scope. |
| Breeze generator overwrites/hardcodes routes or layout assumptions that conflict with Filament's assets/Tailwind setup. | Low | Install with `--no-interaction` defaults; Breeze's auth routes live in `routes/auth.php` (kept out of the Filament panel); Tailwind merges with the project's existing setup. Verify in specs/tasks; rollback is `--dev` removal. |
| Filament admin reaching `/dashboard` (web guard) — an admin/teacher logged into the web guard could see the empty student dashboard. | Low | `role:STUDENT` middleware on the dashboard group denies non-STUDENT roles. CheckRole is variadic and already supports this. |
| Stale login link: the join page still pointed guests to `/admin/login` before this change. | Low | Replaced by `?redirect=/clase/unirse/{code}` targeting the Breeze login route; covered by the extended `ClassInvitationFlowTest`. |

## Rollback Plan

- `composer remove laravel/breeze` and delete all Breeze-generated files (`routes/auth.php`, `app/Livewire/Auth/*`, `resources/views/livewire/{auth,layouts,components}`, `app/View/Components/*` Breeze adds, `tests/Feature/Auth/*`); remove the `routes/auth.php` include from `routes/web.php`.
- `php artisan migrate:rollback` to drop `class_user`.
- Remove `subscribedClasses()` from `User` and `students()` from `SchoolClass`.
- Revert `JoinClassController` to the single `show()` action (remove `join()`).
- Restore `resources/views/class/join.blade.php` to the TBD placeholder and the `/admin/login` link; remove the `class.join.action` and `dashboard` routes from `routes/web.php`.
- Delete `app/Livewire/Dashboard.php` and `resources/views/livewire/dashboard.blade.php`.
- Delete `tests/Feature/{StudentJoinClass,StudentDashboard,ClassUserPivot}Test.php` and revert `ClassInvitationFlowTest`; remove Breeze's `tests/Feature/Auth/*`.
- Revert the README student section.
- No class, material, exam, or teacher-side data is affected: the pivot removal only drops the student subscription link. The public invitation page returns to its pre-change state.

## Dependencies

- `platform-scaffold` — Laravel 13 / Filament v5 / Livewire / User with `role` enum (STUDENT case) and `suspended_at`; the auth foundation.
- `admin-teacher-management` — TEACHER role and Filament auth (unchanged; the student stack is separate).
- `teacher-class-management` — `SchoolClass` with `teacher_id` and `invitation_code` consumed by the join route.
- `class-invitation-flow` — the public `/clase/unirse/{code}` page and `JoinClassController::show` extended by this change.
- `teacher-study-material-management` and `teacher-exam-management` — the materials/exams whose counts render on the dashboard.
- `laravel/breeze` (new dev dependency; NOT yet installed) and its generation of the Livewire stack.

## Future Capabilities Enabled

- `student-exams` / exam engine: `student_attempts` (PRD §5.8) + `student_answers` (§5.9) tables, the exam-taking Wizard UI (one question at a time with a server-validated countdown timer), auto-submit on timeout (PRD §4.1), and single-attempt enforcement. The cached UI decision (#4) is a wizard.
- Grading engine: strict MCQ rule — all correct AND no incorrect selected = full points, else partial/zero per product decision — `score_obtained` persistence, and the "Tu calificación es: X / Y" instant result (PRD §4.1).
- Teacher reports (PDF + Excel) via `barryvdh/laravel-dompdf` + `maatwebsite/excel`, which require attempts data.
- Email verification, profile editing, and password change — Breeze scaffolding is present and wired but left no-op; follow-up enables them once a mailer is configured.
- Student notifications and live class materialization.

## Success Criteria

- [ ] A student can register and log in via the Breeze + Livewire stack using the existing `User` model.
- [ ] A guest visiting `/clase/unirse/{code}` sees a login link carrying `?redirect=/clase/unirse/{code}`; after Breeze login they return to the join page.
- [ ] An authenticated student sees an "Unirse a clase" button (replacing TBD); posting it creates a `class_user` row and redirects to `/dashboard` with a success flash.
- [ ] A duplicate join is a graceful no-op (no error, no duplicate row) thanks to `firstOrCreate` + the DB unique constraint.
- [ ] `/dashboard` requires auth and rejects non-STUDENT roles; it lists the student's subscribed classes as cards (title, description, #materials, #exams) and shows the empty state when none are subscribed.
- [ ] Deleting a class removes its `class_user` rows; deleting a user removes their subscriptions (cascaded at the DB level).
- [ ] The Filament admin panel at `/admin` remains inaccessible to the STUDENT role, and `/dashboard` remains inaccessible to ADMIN/TEACHER.
- [ ] Pest tests pass: Breeze `Auth/{Registration,Login}Test`, extended `ClassInvitationFlowTest`, `StudentJoinClassTest`, `StudentDashboardTest`, `ClassUserPivotTest`.