# Tasks: Student Module — Auth, Join, and Multi-Class Dashboard

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~350–450 authored (Breeze-generated ~1,200 lines excluded) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | single PR |
| Delivery strategy | ask-always |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Breeze auth + data layer + join flow + dashboard + tests + docs | PR 1 | `vendor/bin/pest` (all tests) | `php artisan route:list` verifying all new routes + manual smoke (register → login → join → dashboard) | `composer remove laravel/breeze`, delete `routes/auth.php`, `php artisan migrate:rollback`, revert 6 custom files to pre-change state |

## Phase 1: Breeze Installation

- [x] 1.1 Add `laravel/breeze` dev dependency to `composer.json`, run `composer install`
  - Verify: `composer show laravel/breeze` lists the package
  - **NOTE**: Used `blade` stack instead of `livewire` — the `livewire` and `livewire-functional` stacks require Livewire v3 which conflicts with Filament v5's Livewire v4.3.3. Blade stack generates standard controllers/views functionally equivalent for auth.

- [x] 1.2 Run `php artisan breeze:install blade --no-interaction`
  - Verify: `Test-Path routes/auth.php` returns true; Breeze controllers generated

- [x] 1.3 Add `require __DIR__.'/auth.php';` to `routes/web.php`
  - Verify: `php artisan route:list` shows `/login`, `/register`, `/logout` routes

## Phase 2: Data Layer — class_user Pivot

- [x] 2.1 Create migration `*_create_class_user_table.php`: `id`, `class_id` FK→classes cascade, `user_id` FK→users cascade, timestamps, `UNIQUE(class_id, user_id)`
  - Verify: `php artisan migrate` runs without error; migration applied

- [x] 2.2 Create `app/Models/ClassUser.php` extending `Illuminate\Database\Eloquent\Relations\Pivot`
  - Verify: `Test-Path app/Models/ClassUser.php` returns true

- [x] 2.3 Add `subscribedClasses(): BelongsToMany` to `User` via `class_user` with `withTimestamps()`
  - Verify: Relationship returns BelongsToMany with correct pivot keys

- [x] 2.4 Uncomment `students(): BelongsToMany` in `SchoolClass`, add `withTimestamps()`
  - Verify: Relationship returns BelongsToMany with correct pivot keys

## Phase 3: Join Flow — Controller, Routes, View

- [x] 3.1 Add `join(Request, $code)` to `JoinClassController`: `firstOrFail` by `invitation_code`, `ClassUser::firstOrCreate`, redirect `route('dashboard')` with success flash
  - Verify: `php artisan route:list --name=class.join.action` lists POST route

- [x] 3.2 Add POST `/clase/unirse/{code}/join` (auth, `class.join.action`) and GET `/dashboard` (auth+`role:STUDENT`, `dashboard`) to `routes/web.php`
  - Verify: `php artisan route:list --name=dashboard` lists the GET route with `auth,role:STUDENT` middleware

- [x] 3.3 Register `role` alias in `bootstrap/app.php`: `$middleware->alias(['role' => CheckRole::class]);`
  - Verify: `Get-Content bootstrap/app.php | Select-String 'role.*CheckRole'` returns match

- [x] 3.4 Update `resources/views/class/join.blade.php`: replace TBD button with POST form (CSRF, `route('class.join.action', $code)`) and change login link to `route('login', ['redirect' => route('class.join.show', $code)])`
  - Verify: `Get-Content resources/views/class/join.blade.php | Select-String TBD` returns no matches

## Phase 4: Student Dashboard

- [x] 4.1 Create `app/Livewire/Dashboard.php`: class-based Livewire component rendering `Auth::user()->subscribedClasses()->withCount(['studyMaterials','exams'])->get()`
  - Verify: `Test-Path app/Livewire/Dashboard.php` returns true

- [x] 4.2 Create `resources/views/livewire/dashboard.blade.php`: cards with title, description, counts per class; empty state "You haven't joined any classes yet."
  - Verify: `Test-Path resources/views/livewire/dashboard.blade.php` returns true

## Phase 5: Pest Tests

- [x] 5.1 Extend `tests/Feature/ClassInvitationFlowTest.php`: two new tests — (a) auth student sees join form not TBD, (b) guest login link carries `?redirect` param
  - Verify: `vendor/bin/pest tests/Feature/ClassInvitationFlowTest.php` all pass

- [x] 5.2 Create `tests/Feature/StudentJoinClassTest.php`: pivot creation + redirect, idempotent duplicate, 404 on nonexistent code, 302 unauthenticated
  - Verify: `vendor/bin/pest tests/Feature/StudentJoinClassTest.php` all pass

- [x] 5.3 Create `tests/Feature/StudentDashboardTest.php`: auth gate, `role:STUDENT` gate, cards render, empty state, non-STUDENT denied (403)
  - Verify: `vendor/bin/pest tests/Feature/StudentDashboardTest.php` all pass

- [x] 5.4 Create `tests/Feature/ClassUserPivotTest.php`: schema columns, UNIQUE constraint violation, cascade delete, relationship resolution with timestamps
  - Verify: `vendor/bin/pest tests/Feature/ClassUserPivotTest.php` all pass

- [x] 5.5 Run Breeze default auth tests: `vendor/bin/pest tests/Feature/Auth/`
  - Verify: all Registration, Login, PasswordReset tests pass

## Phase 6: Documentation & Final Smoke

- [x] 6.1 Add "Student Auth and Multi-Class Subscription" section to `README.md`: register→login→join→dashboard flow, Filament coexistence, no-mailer limitation, deferred items
  - Verify: `Get-Content README.md | Select-String "Student Auth"` returns match

- [x] 6.2 Run full test suite: `php artisan test`
  - Verify: exit code 0, 98 tests passed (61 existing + 37 new/modified)

- [x] 6.3 Verify routes: `php artisan route:list` shows `/login`, `/register`, `/dashboard`, `/clase/unirse/{code}`, `/clase/unirse/{code}/join`
  - Verify: all five route patterns visible in output
