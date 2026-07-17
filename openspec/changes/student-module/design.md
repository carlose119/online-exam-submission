# Design: Student Module — Auth, Join, and Multi-Class Dashboard

## Technical Approach

Two coexisting auth stacks share `User`: **Filament** (admin guard, `/admin`) for ADMIN/TEACHER, **Breeze + Livewire** (web guard) for STUDENT. Breeze installed via `composer require laravel/breeze --dev && php artisan breeze:install livewire --no-interaction`. `class_user` pivot (PRD §5.3) with DB `UNIQUE` + `firstOrCreate` idempotency. `JoinClassController` gains `join()`. Join page TBD becomes a POST form. `/dashboard` is a Livewire component behind `auth` + `role:STUDENT`. Builds on `platform-scaffold`, `teacher-class-management`, `class-invitation-flow`.

## Architecture Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Breeze stack | `livewire-class-based` | Avoids Flux library; matches existing Livewire v4.3.3; no new JS dependency. |
| Auth guard | Share `web` guard (Breeze default) | `User` already has `role` enum; `role:STUDENT` middleware gates dashboard. No separate guard complexity. |
| Join idempotency | `firstOrCreate` + DB `UNIQUE(class_id, user_id)` | Graceful UX no-op from Eloquent; hard DB guarantee against races. |
| Dashboard rendering | Livewire component | Fits Breeze stack; enables future reactive updates without refactoring. |
| `role` alias | `$middleware->alias(['role' => CheckRole::class])` in `bootstrap/app.php` | Laravel 11+ idiom; zero new middleware class. |
| Cascade delete | `cascadeOnDelete()` on both FKs | Matches PRD §5.3; deleting class/user removes subscriptions. |

## Data Flow

```
Guest → /clase/unirse/{code}
  ├─ (no session) → login link → /login?redirect=/clase/unirse/{code} → Breeze login → back to join page
  └─ (auth STUDENT) → "Unirse a clase" button
        └─ POST /clase/unirse/{code}/join → JoinClassController::join()
              → firstOrCreate → class_user pivot
              → redirect /dashboard → Dashboard Livewire → subscribedClasses withCount → cards | empty state
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `composer.json` | Modify | Add `laravel/breeze` dev dependency. |
| `database/migrations/*_create_class_user_table.php` | Create | `id`, `class_id`+`user_id` FKs cascade, timestamps, `UNIQUE(class_id, user_id)`. |
| `routes/auth.php` | Create | Breeze auth routes. |
| `app/Livewire/Auth/*` (4) | Create | Breeze auth components. |
| `resources/views/livewire/auth/*` (6) | Create | Breeze auth views. |
| `resources/views/livewire/layouts/*` (2) | Create | Breeze layouts. |
| `resources/views/components/*` (~10) | Create | Breeze UI components. |
| `app/View/Components/{App,Guest}Layout.php` | Create | Breeze layout classes. |
| `app/Models/ClassUser.php` | Create | Pivot model for `firstOrCreate`. |
| `app/Livewire/Dashboard.php` | Create | Queries `subscribedClasses()->withCount(['studyMaterials','exams'])`. |
| `resources/views/livewire/dashboard.blade.php` | Create | Cards + empty state; replaces Breeze's default. |
| `tests/Feature/Auth/{Registration,Login}Test.php` | Create | Breeze defaults. |
| `tests/Feature/StudentJoinClassTest.php` | Create | Join: pivot creation, idempotency, auth gate, 404. |
| `tests/Feature/StudentDashboardTest.php` | Create | Dashboard: auth+role gate, cards, empty state. |
| `tests/Feature/ClassUserPivotTest.php` | Create | Schema, UNIQUE, cascade, relationships. |
| `app/Models/User.php` | Modify | Add `subscribedClasses(): BelongsToMany` with `withTimestamps()`. |
| `app/Models/SchoolClass.php` | Modify | Uncomment `students()`, add `withTimestamps()`. |
| `app/Http/Controllers/JoinClassController.php` | Modify | Add `join()`: firstOrFail→firstOrCreate→redirect. |
| `resources/views/class/join.blade.php` | Modify | TBD → POST form + CSRF button; guest link → `?redirect`. |
| `routes/web.php` | Modify | Add POST `class.join.action`, GET `dashboard`, include `auth.php`. |
| `bootstrap/app.php` | Modify | Register `role` alias → `CheckRole`. |
| `tests/Feature/ClassInvitationFlowTest.php` | Modify | Assert join form + `?redirect` login link. |
| `README.md` | Modify | New student section: flow, coexistence, no-mailer, deferred items. |

## Interfaces / Contracts

**`class_user` migration** — `foreignId('class_id')->constrained('classes')->cascadeOnDelete()`; `foreignId('user_id')->constrained('users')->cascadeOnDelete()`; timestamps; `unique(['class_id', 'user_id'])`.

**`JoinClassController::join()`** — `firstOrFail` by `invitation_code`, `ClassUser::firstOrCreate([...], [])`, redirect `route('dashboard')` with success flash. Auth via route middleware + `Auth::check()` defense-in-depth.

**Middleware alias** — `bootstrap/app.php`: `$middleware->alias(['role' => CheckRole::class])`. Enables `Route::middleware(['auth', 'role:STUDENT'])`.

**Deferred**: `MustVerifyEmail` not on `User`; verify-email wired but no-op. Password reset works but no mailer. Documented in README.

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Feature — Auth | Registration creates STUDENT, login/logout, invalid creds. | Pest v4.7.5, `RefreshDatabase`, Breeze defaults. |
| Feature — Join | Pivot row, idempotency, 404, 302 unauthenticated. | `StudentJoinClassTest`: arrange class→POST→assert. |
| Feature — Dashboard | Auth gate, `role:STUDENT`, cards, empty state, teacher 403. | `StudentDashboardTest`: `actingAs()`→assert. |
| Feature — Pivot | Schema, UNIQUE, cascade, relationships. | `ClassUserPivotTest`: duplicate insert→exception; delete cascade. |
| Feature — Invitation | Join form for auth, `?redirect` for guests. | Extend `ClassInvitationFlowTest` (2 new tests). |

Tests written AFTER implementation (`tdd: false`). SQLite `:memory:`.

## Threat Matrix

N/A — no routing engine, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. All routes are standard Laravel definitions behind `auth` and `role:STUDENT`.

## Migration / Rollout

`php artisan migrate` creates `class_user`. No data migration needed. Full rollback plan in proposal §Rollback Plan.

## Open Questions

None — all architectural decisions resolved in the proposal.
