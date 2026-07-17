# Archive Report: student-module

**Change**: Student Module — Auth, Join, and Multi-Class Dashboard  
**Archive Date**: 2026-07-18  
**Archived To**: `openspec/changes/archive/2026-07-18-student-module/`  
**Artifact Store Mode**: openspec  
**Verifier**: sdd-archive-carlos (sub-agent)

---

## Intent (from proposal.md)

Changes 1–4 built the teacher side (admin, classes, materials, exams). Students still had no auth, no way to subscribe to a class, and no dashboard. The public invitation page (`class-invitation-flow`) already rendered class info but its authenticated join affordance was a TBD placeholder that created no subscription, and its login link pointed to `/admin/login` (wrong audience). This change delivered the first student slice: Breeze + blade auth, an explicit join button + POST route, the `class_user` pivot, and a multi-class dashboard. Exam taking, the grading engine, and the timer were explicitly deferred.

---

## What Was Delivered

### New Capabilities

| Capability | Spec Path | Requirements | Scenarios |
|------------|-----------|-------------|-----------|
| `student-auth` | `openspec/specs/student-auth/spec.md` | 10 | 11 |
| `student-class-subscription` | `openspec/specs/student-class-subscription/spec.md` | 7 | 13 |

### Extended Capabilities

| Capability | Action | Details |
|------------|--------|---------|
| `class-invitation-flow` | EXTENDED via delta merge | 3 ADDED requirements (Student Join Button, Guest Auth Redirect Flow, Idempotent Join) merged into canonical spec. Total: 10 requirements (7 original + 3 added), comprehensive scenarios covering both old and new behavior. |

### Implementation

- **21 implementation tasks** across 6 phases, all completed
- **37 new files + 9 modified** (per the design document)
- **104 tests pass** (297 assertions, 0 failures, 0 regressions)

---

## Verify Verdict

**PASS** (after re-verify round 2)

| Round | Verdict | Finding Count | Details |
|-------|---------|---------------|---------|
| Round 1 | PASS WITH WARNINGS | 1 WARNING (W1) | Post-login redirect not returning to join page |
| Round 2 (re-verify) | PASS | 0 warnings, 0 critical | W1 resolved by commit `7e27248` |

**Final Compliance**: 17/17 spec requirements pass (10 student-auth + 7 student-class-subscription)  
**Final Test Count**: 104 tests, 297 assertions, all passing (12.30s)

---

## Resolved Security Fix Narrative

### TeacherResource Access Control (commit `17bb4ef`)

**Context**: Mid-cycle user request raised during the student-module apply phase. The user discovered that teachers could manage other teachers via `/admin/teachers` because `AdminPanelProvider` allowed both `ADMIN` and `TEACHER` roles into the Filament panel with no per-resource access control.

**Fix**: Added `canAccess()` override to `TeacherResource` that returns `true` ONLY for users with `role === 'ADMIN'`:

```php
public static function canAccess(): bool
{
    return auth()->user()?->role === 'ADMIN';
}
```

**Impact**: Teachers can no longer create, edit, suspend, or delete other teachers. The TeacherResource is hidden from teacher navigation and direct URL access returns 403. The per-resource control is the correct Filament v5 idiom (the panel-level `CheckRole:ADMIN,TEACHER` middleware is preserved for other resources).

**Verdict**: RESOLVED on `master`. Covered by 2 new Pest tests in `TeacherResourceTest.php`.

---

## Resolved Redirect Fix Narrative

### Post-Login Redirect Preservation (commit `7e27248`)

**Context**: The first verify round (2026-07-17) issued a WARNING (W1) because the guest join page's login link correctly carried `?redirect=/clase/unirse/{code}`, but after login the user landed on `/dashboard` instead of returning to the join page. The Breeze login form did not preserve the `redirect` parameter, and `AuthenticatedSessionController::store()` only used `redirect()->intended(route('dashboard'))`.

**Fix**: Commit `7e27248` made three changes:
1. `AuthenticatedSessionController::create()` passes `request('redirect')` to the login view
2. `resources/views/auth/login.blade.php` conditionally renders a hidden `redirect` input when the parameter is present
3. `AuthenticatedSessionController::store()` reads `request('redirect')`, validates it via a `safeRedirect()` helper (relative URLs and same-host absolute URLs accepted; external URLs rejected), and uses the safe URL as the redirect target

**Impact**: Guests who click "Log in to join" → login via Breeze → returned to the join page. Open-redirect protection prevents external URL abuse (falls back to `/dashboard`).

**Verdict**: RESOLVED on `master`. Covered by 4 new Pest tests in `tests/Feature/Auth/LoginRedirectTest.php`.

---

## Resolved Apply-Phase Deviations

### 1. Breeze Stack — RESOLVED

- **Design prescribed**: `livewire-class-based` (Breeze v2 Livewire stack).
- **Implementation used**: `blade` stack via `php artisan breeze:install blade --no-interaction`.
- **Reason**: The `livewire` and `livewire-functional` stacks require Livewire v3, which conflicts with Filament v5's Livewire v4.3.3. The blade stack provides functionally equivalent auth (registration, login, password reset, logout) via standard controllers and views.
- **Impact**: No functional regression; the design's intent (separate Breeze-based auth stack for students) is preserved.

### 2. Hash::make Removal — RESOLVED

- **Issue**: Breeze's default controllers use `Hash::make()` on the password. The `User` model already has `setPasswordAttribute` and a `'password' => 'hashed'` cast, so explicit `Hash::make()` causes double-hashing (same C1 bug pattern from scaffold-and-admin).
- **Fix**: Removed explicit `Hash::make()` from `RegisteredUserController.php`, `PasswordController.php`, and `NewPasswordController.php`. All three now pass plain text to the model.
- **Impact**: Prevents the double-hash bug. All auth tests pass, proving passwords are hashed correctly and verifiable.

### 3. SchoolClass::exams() Foreign Key — RESOLVED

- **Issue**: `SchoolClass::exams()` without explicit foreign key defaulted to `school_class_id`, but the actual column is `class_id`.
- **Fix**: Added explicit `foreignKey: 'class_id'` to the `hasMany` relationship.
- **Impact**: `withCount('exams')` queries the correct column and returns accurate exam counts on the dashboard.

---

## Capability Archive Summary

| Capability | Treatment | Requirements | Scenarios |
|------------|-----------|-------------|-----------|
| `student-auth` | NEW — canonical spec created | 10 | 11 |
| `student-class-subscription` | NEW — canonical spec created | 7 | 13 |
| `class-invitation-flow` | EXTENDED — 3 ADDED requirements merged | 10 (7 original + 3 added) | Updated with new scenarios |
| **Totals** | 2 NEW + 1 EXTENDED | 20 total (17 new + 3 added) | 24 new scenarios |

---

## Commit Chain (10 commits on `master`, not pushed to origin)

| # | Hash | Message |
|---|------|---------|
| 1 | `f70ed6e` | chore: install Breeze (laravel/breeze) with blade stack |
| 2 | `96927ce` | feat: add class_user pivot with cascade FKs, unique constraint, and model relationships |
| 3 | `7bbeafe` | feat: add join flow, dashboard, and role middleware |
| 4 | `fb97e2b` | feat: add student dashboard Livewire component with class cards and empty state |
| 5 | `b6636c2` | test: add student auth, join, dashboard, and pivot tests |
| 6 | `51a36c2` | docs: add Student auth and multi-class subscription section to README |
| 7 | `17bb4ef` | fix: restrict TeacherResource access to ADMIN only via canAccess() override |
| 8 | `7e27248` | fix: preserve ?redirect query param in Breeze login flow to return to join page |
| 9 | `350bd4a` | docs: update Teacher Classes section to reflect the new student join flow |
| 10 | `f49ce8b` | docs(student-module): commit OpenSpec artifacts |

---

## Archive Contents

| Artifact | Status |
|----------|--------|
| `proposal.md` | ✅ |
| `design.md` | ✅ |
| `tasks.md` | ✅ (21/21 tasks complete) |
| `verify-report.md` | ✅ (PASS, 104 tests, 297 assertions) |
| `specs/student-auth/spec.md` | ✅ |
| `specs/student-class-subscription/spec.md` | ✅ |
| `archive-report.md` | ✅ (this file) |

---

## Canonical Specs Updated

- `openspec/specs/student-auth/spec.md` — NEW canonical spec (10 requirements, 11 scenarios)
- `openspec/specs/student-class-subscription/spec.md` — NEW canonical spec (7 requirements, 13 scenarios)
- `openspec/specs/class-invitation-flow/spec.md` — MODIFIED (merged 3 ADDED requirements; now 10 total)

---

## Intentional Archive Decisions

- **Tasks.md stale checkboxes**: None found. All 21 tasks correctly marked `[x]`.
- **Partial archive**: Complete. All artifacts present.
- **Class-invitation-flow merge**: The original requirement #2 (Auth-Aware Join Affordance with TBD and `/admin/login`) is preserved for historical reference with a note that it is superseded by the 3 student-module ADDED requirements. This follows the orchestrator's instruction to preserve all 7 existing requirements and add 3 new ones, totaling 10 requirements.

---

## SDD Cycle Complete

The `student-module` change has been fully planned, implemented, verified, and archived. All canonical specs are updated. The change directory is archived and the original is removed.

### Next Recommended Change: `exam-engine`

The natural next slice is the student-side exam-taking engine:
- `student_attempts` and `student_answers` tables (PRD §5.8, §5.9)
- Exam-taking wizard UI (one question at a time with server-validated countdown timer)
- Auto-submit on timeout (PRD §4.1)
- Single-attempt enforcement
- Grading engine: strict MCQ rule, `score_obtained` persistence
- "Tu calificación es: X / Y" instant result display (PRD §4.1)
- Teacher reports (PDF + Excel) once attempt data exists

This is the natural next slice because student auth + subscription is done, and the core value proposition (students taking exams and getting graded) depends on these features.
