# Proposal: Student Profile

## Intent

The student dashboard (`/dashboard`) accumulates many capabilities — subscribed classes, available exams, completed exams, upcoming live meetings — but the student has no single place to see their own identity (name, email, role) and the full roster of classes they joined. PRD §3.4 lists student-side interfaces without an explicit profile. This change adds a read-only `/profile` page surfaced from the dashboard via a "Mi perfil" link, giving students a consolidated identity + subscription view and establishing the route/shape that future enhancements (exam history, meeting history, password change) will extend.

## Scope

### In Scope
- New Livewire component `app/Livewire/StudentProfile.php` (full-page component) rendering: user name, email, role badge, and subscribed classes list. READ-ONLY — no form, no editing.
- New Blade view `resources/views/livewire/student-profile.blade.php` with card layout: user info header + grid of class cards.
- New route `GET /profile` (no prefix), named `profile.show`, behind `auth` + `role:STUDENT` middleware (`CheckRole:STUDENT` alias — same pattern as `/dashboard`).
- Subscribed classes query: `$user->subscribedClasses()->withCount(['studyMaterials','exams','meetings'])->with('teacher')->orderByPivot('created_at','desc')`.
- Per-class card: title (h2), teacher.name (subtitle), joined_at formatted `diffForHumans()` + `format('M j, Y')`, 3 count badges ("N materials", "N exams", "N meetings"). No "Unjoin", no "View class" link.
- Empty state: "Aún no te has unido a ninguna clase. Pide un link de invitación a tu teacher."
- Extend `app/Livewire/Dashboard.php` Blade to add a "Mi perfil" text link in the header/nav pointing to `route('profile.show')`.
- New Pest test `tests/Feature/StudentProfileTest.php` (7 scenarios): renders for student (200), 403 for teacher/admin, correct user data, correct subscribed classes with counts, empty state, ordering by joined_at DESC, "Mi perfil" link visible to students.
- README "Student profile" section after "Live class materialization".

### Out of Scope
- Exam history (attempts + detailed scores) on the profile — deferred.
- Meeting history (past meetings attended) on the profile — deferred.
- Password change (with or without email verification) — deferred.
- Email change — deferred.
- Profile editing (name, email editable) — deferred.
- Profile picture / avatar — deferred.
- "Unjoin" button for leaving a class — deferred.
- Email verification for the student — deferred (per `student-auth` limitation).
- Admin/teacher own profile view — different change.

## Capabilities

> Research of `openspec/specs/` confirms 15 existing canonical specs. This change introduces one new capability and lightly extends one existing capability (additive, display-only).

### New Capabilities
- `student-profile`: Read-only student profile page at `/profile` showing identity (name, email, role) and subscribed classes with per-class counts and joined_at; surfaced from the dashboard via "Mi perfil" link.

### Modified Capabilities
- `student-class-subscription`: The `/dashboard` gains a "Mi perfil" header/nav link to `profile.show` (additive navigation, display-only; existing sections, requirements, and scenarios unchanged).

> The profile reuses the `student-auth` `User` model (name, email, role) and the `student-class-subscription` `User::subscribedClasses()` relationship as-is — no spec-level change to those capabilities.

## Approach

Follow the existing `app/Livewire/Dashboard.php` pattern: full-page Livewire component behind `auth` + `role:STUDENT`. Reuse `$user->subscribedClasses()` from `student-class-subscription` with `orderByPivot('created_at','desc')` and `withCount` for the three counts. Store/display convention is the project's established UTC-storage + Carbon display (`diffForHumans()` + `format('M j, Y')`) — same as `live-class-materialization`. No migration, no new composer packages, no Filament involvement (student stack is Livewire + Breeze blade). Risk is low: additive route, additive Blade partial, no schema or model change.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Livewire/StudentProfile.php` | New | Full-page Livewire component; renders identity + subscribed classes. |
| `resources/views/livewire/student-profile.blade.php` | New | Card-layout view: user info header + class card grid + empty state. |
| `routes/web.php` | Modified | Add `GET /profile` named `profile.show`, `auth` + `role:STUDENT` middleware. |
| `app/Livewire/Dashboard.php` + its Blade view | Modified | Add "Mi perfil" header/nav link to `route('profile.show')`. |
| `tests/Feature/StudentProfileTest.php` | New | 7 Pest scenarios. |
| `README.md` | Modified | New "Student profile" section + deferred items. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Read-only nature frustrates users expecting to edit name/email | Med | Document deferred editing explicitly in README + empty-state copy; profile is explicitly first-slice. |
| Deferred exam history (profile shows counts, not attempts) may seem incomplete | Med | Counts gates the value; detailed history is a follow-up `student-exam-history` change. |
| Deferred meeting history (counts only, not attendance) | Low | Documented; meeting counts already surface cadence. |
| Deferred password change forces use of Breeze reset flow | Low | Breeze `/forgot-password` works (no mailer limitation already documented in `student-auth`). |
| Deferred email change | Low | Out of scope; documented. |
| Deferred profile editing | Low | Out of scope; documented. |
| Deferred "Unjoin" button | Low | Out of scope; documented. |
| "Mi perfil" link visible to non-students but route 403s for them | Low | Intentional — link is universal, route guards as elsewhere; non-students see 403. Documented in test scenarios. |

## Rollback Plan

Remove the new route from `routes/web.php`, delete `app/Livewire/StudentProfile.php`, `resources/views/livewire/student-profile.blade.php`, `tests/Feature/StudentProfileTest.php`, and revert the "Mi perfil" link addition in the Dashboard Blade. Remove the README "Student profile" section. No migration to revert (no schema touched); no model modified (only consumed). State is fully additive, so rollback restores prior behavior with zero data dependency.

## Dependencies

- `student-auth` (provides `User` model with `role`, Breeze auth, `role:STUDENT` middleware alias).
- `student-class-subscription` (provides `User::subscribedClasses()` belongsToMany with `withTimestamps()` and the `class_user.created_at` pivot column used for ordering + joined_at display).
- `teacher-class-management` (`SchoolClass::teacher()`, `studyMaterials()`, `exams()` relationships — consumed via `withCount`).
- `live-class-materialization` (`SchoolClass::meetings()` relationship — consumed via `withCount`).
- No new composer packages (Livewire v4 + Breeze blade + Pest v4 already installed).

## Success Criteria

- [ ] `GET /profile` returns 200 for an authenticated STUDENT and 403 for TEACHER/ADMIN (via `role:STUDENT` middleware).
- [ ] Profile shows the authenticated user's name, email, and role badge.
- [ ] Subscribed classes render as cards with title, teacher.name, joined_at (`diffForHumans()` + `format('M j, Y')`), and materials/exams/meetings count badges — ordered by joined_at DESC.
- [ ] Empty state copy appears when a student has zero subscribed classes.
- [ ] Dashboard "Mi perfil" link is visible to authenticated users and points to `route('profile.show')`.
- [ ] New `tests/Feature/StudentProfileTest.php` (7 scenarios) passes within the 400-line authored-code budget.