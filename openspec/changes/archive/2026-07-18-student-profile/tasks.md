# Tasks: Student Profile

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 250–330 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | single PR |
| Delivery strategy | ask-always |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Student profile page + tests | PR 1 | `vendor/bin/pest --filter StudentProfileTest` | `php artisan route:list \| Select-String "profile"` then browser `GET /profile` as student | Delete 3 new files, revert routes/web.php + dashboard.blade.php + navigation.blade.php + README.md |

## Phase 1: Livewire Component

- [x] 1.1 Create `app/Livewire/StudentProfile.php` — full-page component with `#[Layout('layouts.app')]`, `mount()` assigning `$this->user = Auth::user()`, and `render()` querying `subscribedClasses()` with `orderByPivot('created_at','desc')`, `withCount(['studyMaterials','exams','meetings'])`, and `with('teacher')`. Verify: `Test-Path app/Livewire/StudentProfile.php`.

## Phase 2: View + Route

- [x] 2.1 Create `resources/views/livewire/student-profile.blade.php` with inline `<style>` block (match dashboard pattern), identity header (name, email, role badge), class card grid (title, teacher.name, `diffForHumans()` + `format('M j, Y')` joined_at, 3 count badges), and empty-state message. Verify: `Test-Path resources/views/livewire/student-profile.blade.php`.
- [x] 2.2 Modify `routes/web.php`: add `use App\Livewire\StudentProfile;` import; remove lines 37–42 (Breeze `profile.edit`/`update`/`destroy` in `auth` middleware group); add standalone `Route::get('/profile', StudentProfile::class)->name('profile.show')->middleware(['auth', 'role:STUDENT']);`. Verify: `php artisan route:list --name=profile` shows only `profile.show` and `profile.edit` is absent.
- [x] 2.3 Smoke-test profile route: start dev server, authenticate as STUDENT, `GET /profile` returns 200 with rendered identity + classes. Verify: browser shows name, email, role badge, and class cards (or empty state).

## Phase 3: Navigation + Dashboard Wiring

- [x] 3.1 Modify `resources/views/layouts/navigation.blade.php`: replace `route('profile.edit')` with `route('profile.show')` on lines 37 (desktop dropdown) and 83 (responsive nav). Verify: `Select-String -Path resources/views/layouts/navigation.blade.php -Pattern 'profile.edit'` returns zero matches.
- [x] 3.2 Modify `resources/views/livewire/dashboard.blade.php`: add `<a href="{{ route('profile.show') }}">Mi perfil</a>` inside the `.logout` div before the logout form. Verify: `Select-String -Path resources/views/livewire/dashboard.blade.php -Pattern 'Mi perfil'` returns a match.

## Phase 4: Pest Tests

- [x] 4.1 Create `tests/Feature/StudentProfileTest.php` with `RefreshDatabase`, following `StudentDashboardTest.php` pattern: access-control tests (student 200, teacher 403, admin 403, guest→login redirect). Verify: `vendor/bin/pest --filter StudentProfileTest` — 4 tests pass.
- [x] 4.2 Add data-display tests: student name+email+role badge, class cards with title+teacher.name+counts, ordering by joined_at DESC, joined_at `diffForHumans()` + `format()`. Verify: `vendor/bin/pest --filter StudentProfileTest` — 7 tests pass.
- [x] 4.3 Add empty-state test (no subscriptions → Spanish empty message, no cards) and deferred-features-absent test (no exam history, meeting history, password form, unjoin button, or editable fields). Verify: `vendor/bin/pest --filter StudentProfileTest` — 9 tests pass.
- [x] 4.4 Extend `tests/Feature/StudentDashboardTest.php`: add test "dashboard displays Mi perfil link" — assert `get(route('dashboard'))->assertSee('Mi perfil')` and the href contains `route('profile.show')`. Verify: `vendor/bin/pest --filter StudentDashboardTest` — all existing + new test pass.

## Phase 5: README + Final Verification

- [x] 5.1 Modify `README.md`: add "## Student Profile" section after "## Live Class Materialization", documenting the read-only `/profile` page, data displayed, and deferred items (exam history, meeting history, password change, profile editing, unjoin). Verify: `Select-String -Path README.md -Pattern 'Student Profile'`.
- [x] 5.2 Run full test suite: `php artisan test` — all 206+ new tests pass, zero regressions.
- [x] 5.3 Final route verification: `php artisan route:list | Select-String "profile"` shows only `profile.show` (no `profile.edit`, `profile.update`, `profile.destroy`).
