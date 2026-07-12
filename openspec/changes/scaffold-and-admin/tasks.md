# Tasks: Scaffold & Admin Module

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 420 authored; ~5000+ generated skeleton |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 |
| Delivery strategy | ask-always |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Skeleton + Filament + auth/model foundation | PR 1 | `php artisan migrate && php artisan db:seed --class=AdminUserSeeder` | `php artisan serve` → `/admin/login` renders | Delete project directory (greenfield) |
| 2 | TeacherResource + tests + docs | PR 2 | `php artisan test` | `php artisan serve` → admin login → Teacher CRUD round-trip | Delete `app/Filament/Resources/TeacherResource/`, `tests/`, `README.md` |

## Phase 1: Foundation (Skeleton, Filament, Auth, Model)

- [x] 1.1 Scaffold: `composer create-project laravel/laravel .` with `^13.0` (fallback `^12.0` if blocked). Verify: `php artisan --version` returns a Laravel version.
- [x] 1.2 Env: set `.env` `DB_CONNECTION=mysql`, `DB_DATABASE=online_exam_submission`, Laragon defaults. Verify: `php artisan migrate:status` connects without error.
- [x] 1.3 Storage: ensure `config/filesystems.php` public disk root is `storage_path('app/public')`; run `php artisan storage:link`. Verify: `Test-Path public/storage`.
- [ ] 1.4 Filament: `composer require filament/filament:^5.0`, then `php artisan filament:install --panels`. Verify: `Test-Path app/Providers/Filament/AdminPanelProvider.php`.
- [ ] 1.5 Middleware: create `app/Http/Middleware/CheckRole.php` — `handle()` aborts 403 unless `auth()->user()->role` is in allowed list. Verify: file exists with `abort(403)` in code.
- [ ] 1.6 Panel: modify `AdminPanelProvider.php` — register `CheckRole` middleware with `ADMIN,TEACHER` roles, set panel path `/admin`. Verify: `php artisan route:list` shows `/admin` routes.
- [ ] 1.7 Migration: modify `create_users_table` migration — add `$table->enum('role', ['ADMIN','TEACHER','STUDENT'])` and `$table->timestamp('suspended_at')->nullable()`. Verify: `php artisan migrate:fresh` creates columns.
- [ ] 1.8 Model: modify `app/Models/User.php` — add `$fillable` (name,email,password,role,suspended_at), `$hidden` (password,remember_token), `$casts` (email_verified_at→datetime, password→hashed, suspended_at→datetime), `HasFactory`+`Notifiable` traits, `setPasswordAttribute()` mutator using `Hash::make()`. Verify: `php artisan tinker --execute="dd((new App\Models\User(['password'=>'test']))->password)"` outputs a bcrypt hash.

## Phase 2: TeacherResource + Seeder

- [ ] 2.1 TeacherResource: create `app/Filament/Resources/TeacherResource.php` — form (name, email, password, role hidden=TEACHER, suspended_at toggle), table columns, actions (create, edit, suspend toggle, delete, temp-password via `Action` with `Str::random(16)` + `Notification`). Dependencies: 1.8. Verify: file exists with `form()`, `table()`, `getPages()` methods.
- [ ] 2.2 Resource pages: create `CreateTeacher.php`, `EditTeacher.php`, `ListTeacher.php` under `app/Filament/Resources/TeacherResource/Pages/`. Dependencies: 2.1. Verify: each file exists and extends the correct Filament page class.
- [ ] 2.3 Seeder: create `database/seeders/AdminUserSeeder.php` — reads `ADMIN_EMAIL`/`ADMIN_PASSWORD` from env (defaults `admin@example.com`/`password`), `User::firstOrCreate()` for idempotency. Verify: `php artisan db:seed --class=AdminUserSeeder` idempotent (second run no error).
- [ ] 2.4 Register resource: add `TeacherResource::class` to `AdminPanelProvider::getResources()`. Dependencies: 2.1. Verify: `php artisan route:list` shows teacher resource routes.
- [ ] 2.5 Seed + smoke: run migrations fresh, seed admin, verify `/admin/login` renders. Dependencies: 2.3, 2.4. Verify: `php artisan serve` → browser loads `/admin` login page.

## Phase 3: Testing

- [ ] 3.1 Pest install: `composer require pestphp/pest --dev -W`. Verify: `Test-Path vendor/bin/pest` (Windows).
- [ ] 3.2 Smoke test: create `tests/Feature/AdminPanelSmokeTest.php` — `GET /admin`→200, seeded admin authenticates. Dependencies: 2.5, 3.1. Verify: `php artisan test --filter=AdminPanelSmokeTest` passes.
- [ ] 3.3 CRUD test: create `tests/Feature/TeacherResourceTest.php` — create teacher, duplicate email→422, suspend toggle toggles `suspended_at`, mass-assignment guards role. Dependencies: 2.1, 3.1. Verify: `php artisan test --filter=TeacherResourceTest` passes.
- [ ] 3.4 Full suite: run `php artisan test`. Dependencies: 3.2, 3.3. Verify: all tests pass.

## Phase 4: Documentation

- [ ] 4.1 README: create `README.md` with project overview, Laragon setup steps, MariaDB↔MySQL compatibility note, admin credentials reference (no secrets), version constraints. Verify: `Test-Path README.md`.
- [ ] 4.2 Full smoke: `php artisan serve` boots, `/admin` renders login, seeded admin logs in, Teacher create→list→edit→suspend→delete round-trips. Dependencies: 2.5, 3.4. Verify: manual walkthrough succeeds.
