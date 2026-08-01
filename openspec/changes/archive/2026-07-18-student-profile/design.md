# Design: Student Profile

## Technical Approach

Add a read-only student profile page at `/profile` via a new full-page Livewire component (`StudentProfile`), reusing existing Eloquent relationships (`User::subscribedClasses()`, `SchoolClass::teacher()`, `withCount` for materials/exams/meetings). No schema changes, no new composer dependencies. The existing Breeze `/profile` routes (editing) are removed since editing is deferred. The Breeze navigation's Profile link is retargeted to the new route. A single Pest test file covers 8 scenarios.

## Architecture Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Component name | `App\Livewire\StudentProfile` | Matches `Student\ExamController` convention; avoids confusion with Breeze `ProfileController` |
| Route conflict resolution | Remove Breeze `profile.edit/update/destroy`; replace with `GET /profile` → `StudentProfile` | Profile editing is deferred; two routes on the same path would conflict at registration |
| Navigation link target | Replace `route('profile.edit')` with `route('profile.show')` in `layouts/navigation.blade.php` | Navigation already has a "Profile" link; keeping `profile.edit` would 404 after route removal |
| Dashboard "Mi perfil" link | Added inline in `dashboard.blade.php` next to logout button | Spec requirement; Dashboard's `<style>` block overrides layout, making inline link more cohesive |
| Data query | `orderByPivot('created_at','desc')->withCount(['studyMaterials','exams','meetings'])->with('teacher')` | Reuses existing `class_user.created_at` pivot; `withCount` avoids N+1 |
| Layout | `#[Layout('layouts.app')]` | Consistent with Dashboard and all student Livewire components |
| Style | Inline `<style>` + utility classes | Same pattern as Dashboard blade view |

## Data Flow

```
Browser GET /profile
  → auth middleware (guest → redirect /login)
  → role:STUDENT middleware (CheckRole: 403 for non-student)
  → StudentProfile mount(): sets $this->user = Auth::user()
  → render(): subscribedClasses query with withCount + with('teacher')
  → student-profile.blade.php renders identity header + class cards
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Livewire/StudentProfile.php` | Create | Full-page component: `mount()` sets `$this->user`, `render()` queries subscribed classes, returns view |
| `resources/views/livewire/student-profile.blade.php` | Create | Identity header (name, email, role badge) + class card grid + empty state |
| `tests/Feature/StudentProfileTest.php` | Create | 8 Pest scenarios covering access control, data display, ordering, empty state, dashboard link |
| `routes/web.php` | Modify | Remove lines 38-42 (Breeze profile routes); add `GET /profile` → `StudentProfile` named `profile.show` in `auth + role:STUDENT` group |
| `resources/views/layouts/navigation.blade.php` | Modify | Replace `route('profile.edit')` with `route('profile.show')` on lines 37 and 83 |
| `resources/views/livewire/dashboard.blade.php` | Modify | Add "Mi perfil" `<a>` link near logout button, pointing to `route('profile.show')` |
| `README.md` | Modify | Add "Student profile" section after "Live class materialization" |

## Interfaces / Contracts

**StudentProfile component**:

```php
#[Layout('layouts.app')]
class StudentProfile extends Component
{
    public User $user;

    public function mount(): void
    {
        $this->user = Auth::user();
    }

    public function render(): View
    {
        return view('livewire.student-profile', [
            'subscribedClasses' => $this->user->subscribedClasses()
                ->orderByPivot('created_at', 'desc')
                ->withCount(['studyMaterials', 'exams', 'meetings'])
                ->with('teacher')
                ->get(),
        ]);
    }
}
```

**Route**:

```php
Route::get('/profile', App\Livewire\StudentProfile::class)
    ->name('profile.show')
    ->middleware(['auth', 'role:STUDENT']);
```

**View data contract**: `$user` (name, email, role) + `$subscribedClasses` collection with pivot `created_at` and `_count` attributes + each item has `teacher` relation loaded.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Access control: student 200, teacher 403, admin 403, guest redirect | 4 scenarios via `actingAs()` + `get('/profile')` with `RefreshDatabase` |
| Feature | Data display: user info, class cards with counts, ordering, joined_at format | 3 scenarios with `User::create()`, `SchoolClass::create()`, `attach()`, `Carbon::setTestNow()`, `assertSee()` |
| Feature | Dashboard "Mi perfil" link | 1 scenario: `get('/dashboard')->assertSee('Mi perfil')` |

Tests follow `StudentDashboardTest.php` pattern: Pest global `RefreshDatabase`, explicit `User::create()` with role, direct model creation. Written AFTER implementation (`tdd: false` per project config).

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration required. Rollback: delete 3 new files, revert 4 modified files. Zero database state to revert.

## Open Questions

None.
