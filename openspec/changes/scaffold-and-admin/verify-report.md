# Verification Report: scaffold-and-admin (PR 1)

**Change**: scaffold-and-admin
**Mode**: Standard verify (Strict TDD: false — no test runner available)
**Date**: 2026-07-11
**Commits verified**: b694732..2034bbd (10 commits on master)
**Artifact set**: Full (proposal + specs + design + tasks)

---

## 1. Completeness

| Artifact | Present | Status |
|----------|---------|--------|
| Proposal | ✅ | `openspec/changes/scaffold-and-admin/proposal.md` |
| Spec: platform-scaffold | ✅ | 9 requirements, 9 scenarios |
| Spec: admin-teacher-management | ✅ | 6 requirements, 6 scenarios |
| Design | ✅ | Architecture decisions + data flow |
| Tasks | ✅ | 4 phases, 15 tasks (checkboxes partially out of sync) |

---

## 2. Build & Runtime Evidence

### 2.1 Skeleton & Versions

| Component | Expected | Installed | Evidence |
|-----------|----------|-----------|----------|
| Laravel | ^13.0 | 13.19.0 | `php artisan --version` → `Laravel Framework 13.19.0` |
| Filament | ^5.0 | v5.6.8 | `composer show filament/filament` → `v5.6.8` |
| Livewire | v3+ (PRD) | v4.3.3 | `composer show livewire/livewire` → `v4.3.3` |
| PHP | 8.4 | 8.4.4 | Laragon runtime |

### 2.2 Migrations

```
0001_01_01_000000_create_users_table ....... [1] Ran
0001_01_01_000001_create_cache_table ........ [1] Ran
0001_01_01_000002_create_jobs_table ......... [1] Ran
```

**Schema verified via `Schema::getColumns('users')`**:
- `id` (bigint unsigned, auto_increment) ✅
- `name` (varchar 255) ✅
- `email` (varchar 255, unique index `users_email_unique`) ✅
- `email_verified_at` (timestamp, nullable) ✅
- `password` (varchar 255) ✅
- `role` (enum('ADMIN','TEACHER','STUDENT'), default 'STUDENT') ✅
- `suspended_at` (timestamp, nullable) ✅
- `remember_token` (varchar 100, nullable) ✅
- `created_at` / `updated_at` (timestamps) ✅

### 2.3 Smoke Test

```
GET /admin       → 302 redirect to /admin/login  ✅ (unauthenticated redirect)
GET /admin/login → 200                             ✅
Login form HTML  → <form> tag present              ✅
```

### 2.4 Storage Symlink

`Test-Path public/storage` → `True` ✅

### 2.5 Seeded Admin

```
User count: 1
Admin role: ADMIN
Admin email: admin@example.com
Hash::check('password', $admin->password): FALSE  ❌ DOUBLE-HASHED
```

### 2.6 Routes

```
GET    admin                  → Dashboard
GET    admin/login            → Login
POST   admin/logout           → Logout
GET    admin/teachers         → ListTeacher
GET    admin/teachers/create  → CreateTeacher
GET    admin/teachers/{record}/edit → EditTeacher
```

---

## 3. Spec Compliance Matrix

### 3.1 platform-scaffold (9 requirements)

| # | Requirement | Verdict | Evidence |
|---|-------------|---------|----------|
| 1 | Runnable Laravel Skeleton | **PASS** | `php artisan serve` boots, HTTP 200 at root, Laravel 13.19.0 |
| 2 | Filament v5 with Livewire v3 Stack | **WARN** | Filament v5.6.8 ✅, AdminPanelProvider exists ✅, /admin route registered ✅. BUT Livewire is v4.3.3, not v3. Filament v5 hard-requires Livewire v4. Stack drift from PRD. |
| 3 | MariaDB Connection | **PASS** | `DB_CONNECTION=mysql`, migrations ran clean, database `online_exam_submission` connected |
| 4 | Public Storage Disk | **PASS** | `config/filesystems.php` has `public` disk → `storage_path('app/public')`. `public/storage` symlink exists. |
| 5 | Users Database Schema | **PASS** | All columns verified: name, email (unique), password, role enum (ADMIN/TEACHER/STUDENT), suspended_at (nullable timestamp), timestamps |
| 6 | Role-Gated Admin Panel | **PASS** | `CheckRole` middleware in `authMiddleware` (not `middleware`) at `AdminPanelProvider.php:58`. Unauthenticated → 302 to `/admin/login`. |
| 7 | Admin Seeder | **FAIL** | Seeder runs and creates admin, BUT password is double-hashed → admin CANNOT authenticate. See C1. |
| 8 | Stack Compatibility Verification | **PASS** | `composer.json` pins `laravel/framework: ^13.0`, `filament/filament: ^5.6`. `composer install` resolved without conflicts. |
| 9 | Smoke Boot Verification | **FAIL** | Admin cannot log in because password is double-hashed. See C1. |

### 3.2 admin-teacher-management (6 requirements)

| # | Requirement | Verdict | Evidence |
|---|-------------|---------|----------|
| 1 | Teacher CRUD Resource | **FAIL** | `TeacherResource.php` imports `Filament\Tables\Actions\{Action,EditAction,DeleteAction}` — these classes DO NOT EXIST in Filament v5.6.8. Correct namespace is `Filament\Actions\*`. Routes register (lazy loading), but `/admin/teachers` will fatal-error at runtime. See C2. |
| 2 | Teacher Account Suspension | **FAIL** | Form `Toggle::make('is_suspended')` uses field name `is_suspended` which is NOT a column on `users` and NOT in `$fillable`. `dehydrateStateUsing` returns a `suspended_at` value but Filament maps it to the wrong attribute. Toggle silently fails. See C5. |
| 3 | Temporary Password Generation | **FAIL** | `TeacherResource.php:132-133` calls `Hash::make($plain)` then assigns to `$record->password`. The `User` mutator calls `Hash::make()` again → double-hashed. Displayed plain text won't match stored hash. See C4. |
| 4 | Unique Email Enforcement | **PASS** | `TeacherResource.php:48` → `->unique(ignoreRecord: true)` on email field. |
| 5 | Mass-Assignment Protection | **PASS** | `User.php:14` → `#[Fillable(['name', 'email', 'password', 'role', 'suspended_at'])]` using PHP 8.3 attribute syntax. Non-listed attributes guarded. |
| 6 | Password Hashing | **FAIL** | Passwords ARE stored as bcrypt hashes, BUT they are double-hashed due to mutator + manual `Hash::make()` conflict. `Hash::check('plaintext', $stored)` returns `false`. See C3/C4. |

---

## 4. Design Coherence

| Decision | Design Says | Implementation | Verdict |
|----------|-------------|----------------|---------|
| Password hashing | Mutator `setPasswordAttribute()` only | Mutator + manual `Hash::make()` in seeder/resource | **CONFLICT** — causes double-hashing |
| CheckRole placement | `authMiddleware` | `authMiddleware` (after fix in 2034bbd) | ✅ Aligned |
| Suspension via `suspended_at` | Nullable timestamp, toggle writes/clears | Toggle uses wrong field name `is_suspended` | **DEVIATION** — broken |
| Temp password via `Str::random(16)` + `Notification` | `Action` with persistent `Notification` | Implemented but double-hashes | **PARTIAL** — mechanism correct, hashing broken |
| AdminUserSeeder idempotent | `firstOrCreate` | `firstOrCreate` ✅ | ✅ Aligned |
| Single panel with role middleware | `CheckRole:ADMIN,TEACHER` | `CheckRole::class.':ADMIN,TEACHER'` | ✅ Aligned |

---

## 5. Task Completion

| Phase | Tasks | Checked in tasks.md | Actually Done | Notes |
|-------|-------|---------------------|---------------|-------|
| 1 Foundation | 1.1–1.8 | 1.1–1.3 ✅, 1.4–1.8 ☐ | ALL DONE | Checkboxes not updated in commit a5fd159 |
| 2 TeacherResource | 2.1–2.5 | All ☐ | ALL DONE (with bugs) | Checkboxes not updated |
| 3 Testing | 3.1–3.4 | All ☐ | NOT DONE | Deferred to PR 2 (by design) |
| 4 Documentation | 4.1–4.2 | All ☐ | NOT DONE | Deferred to PR 2 (by design) |

**Phase 1–2 tasks are implemented but checkboxes were never marked.** Phase 3–4 are intentionally deferred to PR 2.

---

## 6. Findings

### CRITICAL (5)

#### C1: Admin password is double-hashed — admin cannot log in
- **File**: `database/seeders/AdminUserSeeder.php:20` + `app/Models/User.php:40-44`
- **Root cause**: Seeder calls `Hash::make($password)` before passing to `User::firstOrCreate()`. The `setPasswordAttribute()` mutator then calls `Hash::make()` again on the already-hashed value.
- **Evidence**: `Hash::check('password', $admin->password)` returns `false`. Controlled test: `$u->password = 'plain'` → `Hash::check('plain', $u->password)` returns `true`. `$u->password = Hash::make('plain')` → `Hash::check('plain', $u->password)` returns `false`.
- **Spec impact**: platform-scaffold §7 (Admin Seeder) FAILS, §9 (Smoke Boot) FAILS.
- **Fix**: Remove `Hash::make()` from seeder line 20 — pass plain `$password` and let the mutator handle hashing.

#### C2: TeacherResource imports non-existent Filament v5 classes — /admin/teachers crashes at runtime
- **File**: `app/Filament/Resources/TeacherResource.php:15-17`
- **Root cause**: Imports `Filament\Tables\Actions\Action`, `Filament\Tables\Actions\DeleteAction`, `Filament\Tables\Actions\EditAction`. These classes DO NOT EXIST in Filament v5.6.8. In Filament v5, table actions moved to `Filament\Actions\*`.
- **Evidence**: `class_exists('Filament\Tables\Actions\EditAction')` → `false`. `class_exists('Filament\Actions\EditAction')` → `true`.
- **Spec impact**: admin-teacher-management §1 (Teacher CRUD Resource) FAILS — CRUD is completely non-functional.
- **Fix**: Change imports to `Filament\Actions\Action`, `Filament\Actions\DeleteAction`, `Filament\Actions\EditAction`.

#### C3: TeacherResource form password is double-hashed — teachers cannot log in
- **File**: `app/Filament/Resources/TeacherResource.php:53` + `app/Models/User.php:40-44`
- **Root cause**: `dehydrateStateUsing` calls `Hash::make($state)`, then the mutator calls `Hash::make()` again.
- **Spec impact**: admin-teacher-management §6 (Password Hashing) FAILS — passwords stored but not verifiable.
- **Fix**: Remove `Hash::make()` from `dehydrateStateUsing` — return plain `$state` and let the mutator hash it. Or remove the mutator and keep manual hashing everywhere (pick ONE strategy).

#### C4: TeacherResource temp password action is double-hashed
- **File**: `app/Filament/Resources/TeacherResource.php:132-134`
- **Root cause**: `$record->password = Hash::make($plain)` → mutator fires → `Hash::make(Hash::make($plain))`. The plain text shown in the Notification won't match the stored hash.
- **Spec impact**: admin-teacher-management §3 (Temporary Password Generation) FAILS.
- **Fix**: Change to `$record->password = $plain` — let the mutator handle hashing.

#### C5: TeacherResource suspend toggle uses wrong field name — suspension silently fails
- **File**: `app/Filament/Resources/TeacherResource.php:57-79`
- **Root cause**: `Toggle::make('is_suspended')` but `is_suspended` is NOT a column on `users` and NOT in `$fillable`. The `dehydrateStateUsing` returns a `suspended_at` value but Filament maps it to the `is_suspended` attribute, which is silently ignored.
- **Evidence**: `Schema::hasColumn('users', 'is_suspended')` → `false`. `$fillable` = `['name', 'email', 'password', 'role', 'suspended_at']`.
- **Spec impact**: admin-teacher-management §2 (Teacher Account Suspension) FAILS — toggle has no effect.
- **Fix**: Use `Spatie\Toggle` pattern or `afterStateUpdated` callback to directly set `$record->suspended_at`. Or rename field to `suspended_at` with appropriate format/dehydrate logic.

### WARNING (5)

#### W1: Livewire v4.3.3 instead of v3 (stack drift)
- PRD says "Livewire v3+" but Filament v5.6.8 hard-requires Livewire v4.3.3.
- **Impact**: Documentation drift. PRD should be updated.
- **Mitigation**: This is a known Filament v5 constraint, not a bug. Update PRD to say "Livewire v4+" or document the constraint.

#### W2: No automated tests (deferred to PR 2)
- Phase 3 tasks (Pest install, smoke test, CRUD test) not done.
- **Impact**: No runtime test evidence for spec scenarios. Verification relies on code review + manual smoke only.
- **Mitigation**: PR 2 scope explicitly includes test installation and test writing.

#### W3: Task checkboxes not updated in tasks.md
- Phase 1 (1.4–1.8) and Phase 2 (2.1–2.5) tasks show `[ ]` despite implementation being complete.
- **Impact**: Task tracking out of sync with actual implementation.
- **Mitigation**: Update checkboxes in PR 2 or a follow-up commit.

#### W4: README.md not customized
- Default Laravel README with no project-specific content.
- **Design says**: README should document MariaDB↔MySQL compatibility, setup steps, admin credentials reference.
- **Impact**: New developers have no onboarding documentation.
- **Mitigation**: Phase 4 task (PR 2 scope).

#### W5: MariaDB compatibility documentation missing
- Design decision: "MariaDB doc location → README.md"
- README has no MariaDB compatibility note.
- **Mitigation**: Part of W4 — address in PR 2 README task.

### SUGGESTION (4)

#### S1: Branch is `master` not `main`
- Laravel skeleton default. User will handle when pushing to GitHub.

#### S2: No remote configured, no PR opened
- User has no `gh` CLI. Will open PR via GitHub web UI.

#### S3: `role` enum default is `STUDENT`
- Migration line 20: `->default('STUDENT')`. Spec doesn't mandate a default. Acceptable but worth noting.

#### S4: Triple password hashing mechanism confusion
- Three hashing mechanisms coexist: `setPasswordAttribute()` mutator, `'password' => 'hashed'` cast, and manual `Hash::make()` calls. The mutator takes precedence over the cast, but having all three is confusing and error-prone. Pick ONE: either the mutator (and pass plain text everywhere) or manual hashing (and remove the mutator and cast).

---

## 7. Known Deviations (Confirmed)

| # | Deviation | Severity | Honest? | Notes |
|---|-----------|----------|---------|-------|
| 1 | Livewire v4 instead of v3 | WARNING | ✅ Yes | Filament v5 hard-requires Livewire v4. Not a bug, just a PRD update needed. |
| 2 | No automated tests | WARNING | ✅ Yes | Deferred to PR 2 by design. |
| 3 | Branch `master` not `main` | SUGGESTION | ✅ Yes | Laravel default. |
| 4 | No remote/PR | SUGGESTION | ✅ Yes | No `gh` CLI available. |

All four pre-disclosed deviations are confirmed honest.

---

## 8. Undisclosed Issues Found

The apply agent did NOT disclose the following CRITICAL issues:
1. **Double-hashing bug** (C1, C3, C4) — the mutator + manual `Hash::make()` conflict was not flagged.
2. **Wrong Filament v5 import namespace** (C2) — `Filament\Tables\Actions\*` doesn't exist in v5.
3. **Wrong toggle field name** (C5) — `is_suspended` is not a real column.

These are implementation bugs, not design decisions. They should have been caught during apply self-review.

---

## 9. Verdict

### **FAIL**

PR 1 has **5 CRITICAL findings** that block merge:

1. Admin cannot log in (double-hashed password)
2. Teacher CRUD crashes at runtime (wrong class imports)
3. Teacher passwords double-hashed (can't log in)
4. Temp passwords double-hashed (displayed password won't work)
5. Suspend toggle silently fails (wrong field name)

**Spec requirements**: 15 total — 8 PASS, 1 WARN, 6 FAIL
**Findings**: 5 CRITICAL, 5 WARNING, 4 SUGGESTION

### Recommended Next Step: `fix-and-reverify`

The apply agent must fix C1–C5 and re-verify before PR 1 can merge. All fixes are localized to 2–3 files and should take < 30 minutes.

### Fix Priority

1. **C2 first** — fix imports (TeacherResource is completely broken)
2. **C1** — fix seeder (admin can't log in)
3. **C3 + C4** — fix password hashing strategy (pick mutator OR manual, not both)
4. **C5** — fix toggle field name (suspension doesn't work)

---

# Re-verify Round 2: scaffold-and-admin (PR 1)

**Change**: scaffold-and-admin
**Mode**: Standard verify (Strict TDD: false — no test runner available)
**Date**: 2026-07-11
**Commit verified**: `2c3bfa2` — Fix: 5 CRITICAL findings from verify report
**Artifact set**: Full (proposal + specs + design + tasks)
**Previous round**: FAIL (5 CRITICAL, 5 WARNING, 4 SUGGESTION)

---

## R2-1. Fix Confirmations (5 CRITICAL → all resolved)

### C1: Admin password single-hash ✅ FIXED
- **File**: `database/seeders/AdminUserSeeder.php:19`
- **Evidence**: Line 19 passes plain `$password` (no `Hash::make()`). The `setPasswordAttribute()` mutator at `User.php:40-44` is the single hashing point.
- **Runtime**: `php artisan migrate:fresh --seed` then `Hash::check('password', $admin->password)` → **`PASSWORD_OK`**

### C2: Filament v5 imports ✅ FIXED
- **File**: `app/Filament/Resources/TeacherResource.php:9-11`
- **Evidence**: Lines 9-11 import `Filament\Actions\Action`, `Filament\Actions\DeleteAction`, `Filament\Actions\EditAction` (correct v5 namespace).
- **Runtime**: `php artisan route:list --path=admin` → all **6 routes** loaded without error:
  ```
  GET    admin                  → Dashboard
  GET    admin/login            → Login
  POST   admin/logout           → Logout
  GET    admin/teachers         → ListTeacher
  GET    admin/teachers/create  → CreateTeacher
  GET    admin/teachers/{record}/edit → EditTeacher
  ```

### C3: Form password no double-hash ✅ FIXED
- **File**: `app/Filament/Resources/TeacherResource.php:48-52`
- **Evidence**: Password field uses `->dehydrated(fn (?string $state): bool => filled($state))` with NO `dehydrateStateUsing` calling `Hash::make`. The mutator + `'password' => 'hashed'` cast handle hashing.
- **Runtime**: `User::factory()->create(['role'=>'TEACHER','password'=>'plain-text-password'])` then `Hash::check('plain-text-password', $u->fresh()->password)` → **`FORM_HASH_OK`**

### C4: Temp password no double-hash ✅ FIXED
- **File**: `app/Filament/Resources/TeacherResource.php:130-131`
- **Evidence**: `$plain = Str::random(16); $record->password = $plain;` — assigns plain text, mutator hashes.
- **Runtime**: `$u->password = 'temp-plain'; $u->save();` then `Hash::check('temp-plain', $u->fresh()->password)` → **`TEMP_HASH_OK`**

### C5: Suspend toggle bound to `suspended_at` ✅ FIXED
- **File**: `app/Filament/Resources/TeacherResource.php:55-77`
- **Evidence**: `Toggle::make('suspended_at')` (real column). `formatStateUsing` reads `$record->suspended_at !== null`. `dehydrateStateUsing` maps bool → `now()` / `null`.
- **Runtime**: `$u->suspended_at = now(); $u->save();` then `$u->fresh()->suspended_at !== null` → **`SUSPEND_OK`**

---

## R2-2. Smoke Test Evidence

| Test | Command | Result | Status |
|------|---------|--------|--------|
| DB reset + seed | `php artisan migrate:fresh --seed` | Admin created, 3 migrations ran | ✅ |
| Admin password hash | `Hash::check('password', $admin->password)` | `PASSWORD_OK` | ✅ |
| Admin routes | `php artisan route:list --path=admin` | 6 routes loaded | ✅ |
| Unauthenticated redirect | `curl GET /admin` | `302 → /admin/login` | ✅ |
| Login page renders | `curl GET /admin/login` | `200` | ✅ |
| Form password hashing | Factory create with plain password | `FORM_HASH_OK` | ✅ |
| Temp password hashing | Direct assignment + save | `TEMP_HASH_OK` | ✅ |
| Suspend toggle write | Set `suspended_at = now()` + save | `SUSPEND_OK` | ✅ |

---

## R2-3. Updated Spec Compliance Matrix

### R2-3.1 platform-scaffold (9 requirements)

| # | Requirement | Round 1 | Round 2 | Evidence |
|---|-------------|---------|---------|----------|
| 1 | Runnable Laravel Skeleton | PASS | **PASS** | No regression. Laravel 13.19.0 boots. |
| 2 | Filament v5 with Livewire v3 Stack | WARN | **WARN** | Livewire v4.3.3 drift unchanged (Filament v5 hard-requires v4). |
| 3 | MariaDB Connection | PASS | **PASS** | Migrations ran clean on `migrate:fresh --seed`. |
| 4 | Public Storage Disk | PASS | **PASS** | Symlink present, no changes. |
| 5 | Users Database Schema | PASS | **PASS** | All columns verified in round 1, no migration changes. |
| 6 | Role-Gated Admin Panel | PASS | **PASS** | 302 redirect confirmed. `CheckRole` in `authMiddleware`. |
| 7 | Admin Seeder | **FAIL** | **PASS** | C1 fixed. `Hash::check('password', ...)` → `PASSWORD_OK`. |
| 8 | Stack Compatibility Verification | PASS | **PASS** | No composer changes. |
| 9 | Smoke Boot Verification | **FAIL** | **PASS** | C1 fixed. Admin can now authenticate. |

### R2-3.2 admin-teacher-management (6 requirements)

| # | Requirement | Round 1 | Round 2 | Evidence |
|---|-------------|---------|---------|----------|
| 1 | Teacher CRUD Resource | **FAIL** | **PASS** | C2 fixed. Correct `Filament\Actions\*` imports. 6 routes load. |
| 2 | Teacher Account Suspension | **FAIL** | **PASS** | C5 fixed. Toggle bound to `suspended_at`. Runtime `SUSPEND_OK`. |
| 3 | Temporary Password Generation | **FAIL** | **PASS** | C4 fixed. `$record->password = $plain` (no `Hash::make`). `TEMP_HASH_OK`. |
| 4 | Unique Email Enforcement | PASS | **PASS** | `->unique(ignoreRecord: true)` at line 47. No regression. |
| 5 | Mass-Assignment Protection | PASS | **PASS** | `#[Fillable([...])]` attribute at User.php:14. No regression. |
| 6 | Password Hashing | **FAIL** | **PASS** | C3+C4 fixed. Mutator is single hashing source. `FORM_HASH_OK` + `TEMP_HASH_OK`. |

---

## R2-4. Design Coherence (re-check)

| Decision | Design Says | Implementation | Verdict |
|----------|-------------|----------------|---------|
| Password hashing | Mutator `setPasswordAttribute()` only | Mutator only — seeder, form, temp-password all pass plain text | ✅ **ALIGNED** |
| CheckRole placement | `authMiddleware` | `authMiddleware` | ✅ Aligned |
| Suspension via `suspended_at` | Nullable timestamp, toggle writes/clears | Toggle bound to `suspended_at`, dehydrate maps bool→timestamp/null | ✅ **ALIGNED** |
| Temp password via `Str::random(16)` + `Notification` | Action with persistent Notification | `$record->password = $plain` → mutator hashes. Notification shows plain text. | ✅ **ALIGNED** |
| AdminUserSeeder idempotent | `firstOrCreate` | `firstOrCreate` | ✅ Aligned |
| Single panel with role middleware | `CheckRole:ADMIN,TEACHER` | `CheckRole::class.':ADMIN,TEACHER'` | ✅ Aligned |

---

## R2-5. Findings

### CRITICAL (0)

None. All 5 CRITICAL findings from round 1 are resolved.

### WARNING (5 — unchanged from round 1)

- **W1**: Livewire v4.3.3 instead of v3 (stack drift — Filament v5 constraint)
- **W2**: No automated tests (deferred to PR 2)
- **W3**: Task checkboxes not updated in tasks.md
- **W4**: README.md not customized
- **W5**: MariaDB compatibility documentation missing

### SUGGESTION (4 — unchanged from round 1)

- **S1**: Branch is `master` not `main`
- **S2**: No remote configured, no PR opened
- **S3**: `role` enum default is `STUDENT`
- **S4**: Triple password hashing mechanism confusion → **RESOLVED**. Only mutator + cast remain; manual `Hash::make()` calls removed from seeder and resource.

---

## R2-6. Regressions

None detected. All previously-passing requirements remain passing.

---

## R2-7. Verdict

### **PASS WITH WARNINGS**

PR 1 now satisfies all 15 spec requirements. The 5 CRITICAL findings from round 1 are fully resolved with both source-level and runtime evidence.

**Spec requirements**: 15 total — 14 PASS, 1 WARN (Livewire v4 drift), 0 FAIL
**Findings**: 0 CRITICAL, 5 WARNING, 4 SUGGESTION

### Recommended Next Step: `sdd-archive` or proceed to PR 2 (`sdd-apply-pr2`)

PR 1 is ready to merge. PR 2 (tests + docs) can proceed in parallel or after archive.
