# Proposal: Scaffold & Admin Module (scaffold-and-admin)

## Why (Intent)

Greenfield LMS-Lite has no code yet. This seed change makes the project runnable and delivers the first user-facing capability: Admin manages Teacher accounts via a Filament v5 panel. Without it, every subsequent change (Teacher module, Student module, exams) has nothing to attach to. It also locks the stack decisions (Laravel 13, Filament v5, Livewire v3, MariaDB) and the auth strategy (Filament panel for Admin/Teacher; separate Livewire auth for Student deferred) so later changes inherit a stable base.

## What Changes (Scope)

### In Scope
- `composer create-project laravel/laravel` → runnable Laravel 13 skeleton (PHP 8.4).
- Install Filament v5 + run `filament:install --panels`; verify Livewire v3 (bundled by Filament v5) and Alpine.js.
- `.env` MariaDB connection (`DB_CONNECTION=mysql`, Laragon defaults); document MariaDB↔MySQL compatibility.
- `config/filesystems.php` `public` disk → `storage/app/public`; run `php artisan storage:link`.
- `users` migration: `name`, `email` (unique), `password`, `role` enum (`ADMIN`/`TEACHER`/`STUDENT`), `suspended_at` (nullable timestamp), timestamps.
- Single Filament panel with role middleware (Admin + Teacher access; teacher resources NOT in this change).
- `AdminUserSeeder` → one reachable admin out of the box.
- `TeacherResource` (Filament): list, create, edit, suspend (toggle), delete, optional temp-password generation; unique email validation, `$fillable` mass-assignment guard, `password` hashing.
- Verify Laravel 13 ↔ Filament v5 ↔ Livewire v3 version compatibility; document constraints.
- Smoke verification: `php artisan serve` boots, `/admin` login renders, seeded admin logs in, Teacher CRUD works end-to-end.

### Out of Scope / Future Work
- Tables `classes`, `study_materials`, `exams`, `questions`, `answer_options`, `student_attempts`, `student_answers`, `class_user`.
- Teacher module resources (Classes, Materials, Exams, Reports).
- Student module (Livewire pages, registration by link, subscription, exam taking).
- Student auth UI (login/register) — deferred to a later `student-auth` change.
- Auto-grading engine + server-side timer (strict multiple-choice rule applies then).
- Live-class materialization (URL storage + "Join" button).
- PDF/Excel export (`barryvdh/laravel-dompdf`, `maatwebsite/excel`).

## Capabilities

> Contract for sdd-spec. No existing specs (seed change).

### New Capabilities
- `platform-scaffold`: Laravel 13 + Filament v5 + Livewire v3 skeleton, MariaDB connection, local `public` storage, single role-gated Filament panel, admin seeder, smoke boot.
- `admin-teacher-management`: Admin CRUD over Teacher accounts (create/edit/suspend/delete, temp password), unique email, mass-assignment + hashing guard, role enum.

### Modified Capabilities
- None (no existing `openspec/specs/`).

## Impact (Affected Areas)

| Area | Impact | Description |
|------|--------|-------------|
| project root | New | Laravel 13 skeleton, `composer.json`, `.env`, `artisan` |
| `app/Models/User.php` | New | `role` enum + `suspended_at`; `$fillable`, password cast |
| `database/migrations/*_create_users_table.php` | New | users schema per PRD §5.1 + `suspended_at` |
| `app/Filament/PanelProvider` (Admin) | New | Single panel, role middleware |
| `app/Filament/Resources/TeacherResource.php` | New | Teacher CRUD + suspend + temp password |
| `database/seeders/AdminUserSeeder.php` | New | One seeded admin |
| `config/filesystems.php` | Modified | `public` disk config |
| `.env` | New | MariaDB connection |

## Approach

Single Filament panel for Admin+Teacher with role middleware (not two panels) — one auth surface, simpler routing, and Teacher resources slot in later as gated Resources. Keep Eloquent class `Class` (table `classes`): PHP allows it and Filament handles it; alias only if a real collision surfaces later. Use MariaDB 10.11 (already in Laragon) over requiring strict MySQL. Track suspension via `suspended_at` timestamp (auditable, nullable) instead of Filament's built-in status field.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Laravel 13 release maturity / Filament v5 compatibility (v5 is latest line; Laravel 13 final status to verify at install) | Med | Pin exact versions in `composer.json`; if Filament v5 needs Laravel ^12, fall back to Laravel 12 + document; verify during apply smoke boot |
| MariaDB vs MySQL delta for future features (JSON columns, full-text search, generated indexes) | Med | Use Laravel's `json`/`text` casts; avoid MariaDB-specific FTS now; revisit when Reports/grading arrive |
| No test runner initially | High | Accept for seed; install Pest/PHPUnit during this apply so later changes get TDD optionality |
| `Class` model name collision surprises | Low | Keep `Class`; revisit only if Filament/IDE tooling breaks |
| Single panel couples Admin+Teacher auth | Low | Role middleware isolates resources; split into two panels later only if policy demands |

## Rollback Plan

Delete the generated Laravel skeleton directory and `.env`; remove `openspec/changes/scaffold-and-admin` artifacts. No prior code exists to restore — greenfield revert = start over. Keep `composer.json`/`composer.lock` snapshots to pin versions on rebuild. Re-run seeder only after re-scaffold.

## Dependencies

- PHP 8.4.4, Composer 2.8.11, Node 22.14.0, npm 10.9.2, MariaDB 10.11.9 (all present in Laragon).
- `laravel/laravel`, `filament/filament` (v5) — version compatibility to verify at install.

## Success Criteria

- [ ] `php artisan serve` boots without errors.
- [ ] `/admin` renders the Filament login page.
- [ ] Seeded admin logs in and reaches the Teachers resource.
- [ ] Teacher create → list → edit → suspend → delete round-trips end-to-end.
- [ ] Duplicate teacher email is rejected; password is stored hashed; mass-assignment is blocked.
- [ ] `storage:link` exposes `storage/app/public`.
- [ ] MariaDB migrations run clean; Laravel/Filament/Livewire version constraints documented.