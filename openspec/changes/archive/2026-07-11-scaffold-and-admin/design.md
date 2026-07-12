# Design: Scaffold & Admin Module

## Technical Approach

Install Laravel skeleton at repo root via `composer create-project`, add Filament v5 with a single role-gated panel, and deliver Admin CRUD over Teacher accounts via `TeacherResource`. Greenfield, no legacy constraints. The apply phase resolves Laravel 13 vs 12 at install time: attempt `^13.0` first; if Filament v5's composer constraints block it, fall back to `^12.0`. MariaDB connects via `DB_CONNECTION=mysql`. Auth stays Filament-native for Admin/Teacher; Student auth is deferred.

Covers both capabilities: `platform-scaffold` (spec §1–9) and `admin-teacher-management` (spec §1–6).

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|---|---|---|---|
| Laravel version | ^13.0, fallback ^12.0 | Pin ^12.0 aggressively | Proposal mandates "attempt 13 first"; Filament v5 may require ^12. Conditional install keeps forward ambition without blocking apply |
| Install location | Repo root | Subdirectory (`app/`, `src/`) | Greenfield, no conflicting files; `composer create-project` into `.` is simplest path, no extra nesting |
| Panel structure | Single `AdminPanelProvider` with role middleware | Two separate panels (Admin + Teacher) | Less auth surface, Teacher resources slot in later via gated navigation; split only if policy demands |
| Role enforcement | Custom `CheckRole` middleware on panel + `TeacherResource::can()` per-action | Gates in `AuthServiceProvider`, Laravel Policies | Panel middleware keeps it centralized; per-resource `can()` gives granular control without policy boilerplate |
| Suspension | `suspended_at` nullable timestamp on `users` | Filament `active` boolean field | Auditable (knows *when*), nullable means "not suspended", no default-value ambiguity |
| Password generation | Filament `Action` using `Str::random(16)` + `Hash::make()`, result via `Notification` | Service class, model method, or dedicated generator | Self-contained in form — no extra service layer for a single-form action; Notification shows plaintext once |
| `Class` model name | Keep `Class` (table `classes`) | `Course`, `Classroom` | PHP allows it; Filament handles it; revisit only if IDE/tooling breaks |
| Password hashing | `User::setPasswordAttribute()` mutator calling `Hash::make()` | `$casts` with `hashed`, manual hashing in Resource | Mutator guarantees hashing on ANY `$user->password = ...` path — stronger than cast-only or manual |
| MariaDB doc location | `README.md` | `docs/database.md`, wiki, `.env` comment | Single-source first-read file; developers land on README before anything else |

## Data Flow

```
Browser ──► /admin ──► AdminPanelProvider (middleware: CheckRole)
                           │
                           ├──► /admin/login (Filament default)
                           │       │
                           │       ▼
                           │    User model (users table, role enum)
                           │
                           └──► /admin/teachers ──► TeacherResource
                                                       │
                                                       ├── ListTeachers (Eloquent query scoped to role=TEACHER)
                                                       ├── CreateTeacher (form → $user->fill() → save → hash password via mutator)
                                                       ├── EditTeacher (form → toggle suspended_at)
                                                       └── DeleteTeacher (soft or force)
```

## File Changes

| File | Action | Description |
|---|---|---|
| `app/Http/Middleware/CheckRole.php` | Create | Middleware: aborts 403 unless `auth()->user()->role` is in allowed list |
| `app/Filament/Resources/TeacherResource.php` | Create | Filament resource: form (name, email, password, role=TEACHER, suspended_at), table, actions |
| `app/Filament/Resources/TeacherResource/Pages/{Create,Edit,List}Teacher.php` | Create | Standard Filament page stubs, customized for resource |
| `database/seeders/AdminUserSeeder.php` | Create | Idempotent seeder: checks `User::where('email', ...)->exists()` before creating; credentials from env vars with documented defaults |
| `README.md` | Create | Project overview, setup steps, MariaDB↔MySQL compatibility note, default admin credentials note (no secrets committed) |
| `tests/Feature/AdminPanelSmokeTest.php` | Create | Smoke: GET `/admin` returns 200, seeded admin authenticates |
| `tests/Feature/TeacherResourceTest.php` | Create | Feature: create teacher, duplicate email rejected, suspend toggle |
| `database/migrations/xxxx_create_users_table.php` | Modify | Add `$table->enum('role', ['ADMIN','TEACHER','STUDENT'])` and `$table->timestamp('suspended_at')->nullable()` to default schema |
| `app/Models/User.php` | Modify | Add `$fillable`, `$hidden`, `$casts`, `setPasswordAttribute()`, `HasFactory`, `Notifiable` |
| `app/Providers/Filament/AdminPanelProvider.php` | Modify | Register CheckRole middleware, add TeacherResource, configure panel path `/admin` |
| `config/filesystems.php` | Modify | Ensure `public` disk root is `storage_path('app/public')`, URL is `/storage` |
| `config/auth.php` | Modify | Set `providers.users.model` to `App\Models\User::class` |
| `.env` / `.env.example` | Modify | `DB_CONNECTION=mysql`, `DB_DATABASE=online_exam_submission`, Laragon defaults; `ADMIN_EMAIL`/`ADMIN_PASSWORD` entries |

## Interfaces / Contracts

**User model contract** (app/Models/User.php):
- `$fillable`: `['name', 'email', 'password', 'role', 'suspended_at']`
- `$hidden`: `['password', 'remember_token']`
- `$casts`: `['email_verified_at' => 'datetime', 'password' => 'hashed', 'suspended_at' => 'datetime']`
- Traits: `HasFactory`, `Notifiable`
- Mutator: `setPasswordAttribute($value)` → `$this->attributes['password'] = Hash::make($value)` (guards against accidental plaintext)

**CheckRole middleware contract**:
- Constructor receives `...$roles`; `handle()` calls `abort(403)` if `!auth()->check()` or role not in list.
- Registered in `AdminPanelProvider::middleware()` as `CheckRole::class . ':ADMIN,TEACHER'`

**AdminUserSeeder contract**:
- Reads `ADMIN_EMAIL`/`ADMIN_PASSWORD` from `env()` with fallback defaults (`admin@example.com` / `password`).
- `User::firstOrCreate(['email' => $email], [...])` ensures idempotency.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Smoke | Panel boots, login page renders | HTTP test: `GET /admin` → 200; `GET /admin/login` → 200 |
| Feature | Teacher CRUD lifecycle | `RefreshDatabase` trait; create teacher, assert DB row; submit duplicate email, assert 422; toggle suspend, assert `suspended_at` toggles |
| Feature | Mass-assignment guard | POST teacher create with `role=ADMIN` in payload → role persists as TEACHER |
| Unit | Password hashing | `new User(['password' => 'plain'])` → `Hash::check('plain', $user->password)` returns true |

Pest v3 installed as dev dependency during apply (`composer require pestphp/pest --dev`). Strict TDD activates once Pest is available — apply phase runs tests after implementation (verify phase enforces pass). Dusk/E2E deferred to later changes.

## Threat Matrix

N/A — no shell commands, subprocesses, VCS/PR automation, executable-file classification, or process-integration boundary. This is a standard Laravel web scaffold with Filament admin panel routing.

## Migration / Rollout

No migration required. Greenfield — apply phase creates the entire project from scratch. Rollback: delete project directory and `.env`, re-run `composer create-project`.

## Open Questions

- [x] MariaDB↔MySQL compatibility doc location → **`README.md`** (first-read file for any developer entering the project)
- [x] Filament v5 ↔ Laravel 13 compatibility → **Conditional install**: attempt ^13.0; fall back to ^12.0 if composer constraints block it. Pin exact version in `composer.json` after resolution.
- [ ] Laravel 13 final release status — verify at apply time via `composer show laravel/framework` output; if still RC/beta, document stability note in README.
