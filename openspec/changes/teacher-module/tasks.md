# Tasks: Teacher Module — Classes, Invitation & Syllabus

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~350 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-always |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

Not needed — single PR under 400-line budget.

## Phase 1: Data Layer (Foundation)

- [x] 1.1 Create migration `database/migrations/YYYY_MM_DD_HHMMSS_create_classes_table.php`: `id`, `teacher_id` FK→users (`restrict`), `title` VARCHAR(255), `description` TEXT nullable, `syllabus` LONGTEXT nullable, `invitation_code` VARCHAR(12) unique, timestamps. **Verify**: `php artisan migrate:fresh --seed` exits 0; `php artisan db:show --table=classes` shows columns.

- [x] 1.2 Create `app/Models/SchoolClass.php` (renamed from Class — PHP reserves `class` as keyword, confirmed syntax error in 8.4.4): Eloquent model with `#[Fillable(['title', 'description', 'syllabus', 'teacher_id', 'invitation_code'])]` attribute, `teacher()` belongsTo(User), `students()` declared as belongsToMany(User) with comment that pivot is deferred. **Verify**: `php artisan tinker --execute="App\Models\Class::create(['title'=>'Test','teacher_id'=>1,'invitation_code'=>'T1']); echo App\Models\Class::first()->title;"` prints "Test".

## Phase 2: Filament ClassResource (Core)

- [x] 2.1 Create `app/Filament/Resources/ClassResource.php`: `getEloquentQuery()` scoped to `teacher_id = Auth::id()`, form (title, description, RichEditor syllabus, hidden teacher_id defaulting to `Auth::id()`), table (title searchable, description truncated, teacher.name, invitation_code Badge copyable, EditAction, DeleteAction), `getPages()`. **Verify**: `php artisan filament:list` shows ClassResource in admin panel.

- [x] 2.2 Create `app/Filament/Resources/ClassResource/Pages/ListClasses.php`: `ListRecords` stub referencing `ClassResource`. **Verify**: file exists at path; `php artisan route:list | Select-String 'classes'` shows index route.

- [x] 2.3 Create `app/Filament/Resources/ClassResource/Pages/CreateClass.php`: `CreateRecord` stub; `mutateFormDataBeforeCreate()` injects `teacher_id = Auth::id()` and generates `invitation_code` via `Str::random(8)` with retry-on-collision (max 5, throw RuntimeException on exhaustion). **Verify**: `Test-Path app/Filament/Resources/ClassResource/Pages/CreateClass.php`.

- [x] 2.4 Create `app/Filament/Resources/ClassResource/Pages/EditClass.php`: `EditRecord` stub; `getHeaderActions()` returns `copyInvitationLink` (copies full URL via `$this->js()` + persistent Notification) and `regenerateInvitationCode` (replaces code with fresh `Str::random(8)` + retry + Notification). **Verify**: `Test-Path app/Filament/Resources/ClassResource/Pages/EditClass.php`.

## Phase 3: Public Invitation Route (Integration)

- [x] 3.1 Create `app/Http/Controllers/JoinClassController.php`: `show(Request, string $invitationCode)` — `Class::where('invitation_code', $invitationCode)->firstOrFail()`, passes `$class`, `isAuthenticated` (`Auth::check()`), `loginUrl` (`route('filament.admin.auth.login')`) to view. **Verify**: `Test-Path app/Http/Controllers/JoinClassController.php`.

- [x] 3.2 Create `resources/views/class/join.blade.php`: renders class title, description (escaped), syllabus (raw HTML), and auth-aware affordance: guest → "Log in to join" link to `/admin/login`; authenticated → "TBD: join this class" non-submitting button. **Verify**: `Test-Path resources/views/class/join.blade.php`.

- [x] 3.3 Add `Route::get('/clase/unirse/{invitation_code}', [JoinClassController::class, 'show'])->name('class.join.show');` to `routes/web.php`. **Verify**: `php artisan route:list | Select-String 'clase/unirse'` shows the route.

## Phase 4: Testing

- [x] 4.1 Create `tests/Feature/ClassResourceTest.php`: Pest tests with `RefreshDatabase`, `actingAs`, per spec scenarios — (a) teacher lists only own classes, (b) creates class → `invitation_code` auto-generated & unique, (c) edits own class, (d) deletes own class, (e) cross-teacher edit returns 404, (f) regenerate produces new code ≠ old, (g) syllabus persists via RichEditor, (h) copy-link action exists on edit page, (i) two creates produce different codes. **Verify**: `php artisan test --filter=ClassResourceTest` all green.

- [x] 4.2 Create `tests/Feature/ClassInvitationFlowTest.php`: Pest tests — (a) GET valid code returns 200 with class title/description/syllabus, (b) guest sees "Log in to join" link, (c) authenticated user sees "TBD: join this class" button with no subscription side-effect, (d) GET nonexistent code returns 404. **Verify**: `php artisan test --filter=ClassInvitationFlowTest` all green.

## Phase 5: Documentation & Final Smoke

- [x] 5.1 Add "Teacher classes & invitation flow" section to `README.md`: describes ClassResource CRUD, invitation code generation, public join route, and TBD subscription note. Link to relevant spec files. **Verify**: `Get-Content README.md | Select-String 'Teacher classes'` matches.

- [x] 5.2 Final smoke: `php artisan test` all green (18 existing + all new), `php artisan route:list | Select-String 'clase/unirse'` shows public route, manual smoke of `/admin/classes` in browser (create class, copy link, visit join page). **Verify**: all green + route visible.

## Implementation Order

Topological: Phase 1 (migration → model) → Phase 2 (resource → pages) → Phase 3 (controller → view → route) → Phase 4 (tests) → Phase 5 (docs + smoke). Within Phase 2, 2.1 before 2.2-2.4. Within Phase 3, 3.1 and 3.2 before 3.3.
