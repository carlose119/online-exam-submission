```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:682e971cf7db1de6d6fb15b8fc8f099a4fd3c0577001f4587fac3045fa8517bb
verdict: fail
blockers: 0
critical_findings: 3
requirements: 10/10
scenarios: 25/33
test_command: php artisan test
test_exit_code: 0
test_output_hash: sha256:9cce5017c1f9d247eaa747472ad70291c29064f3d2aa643a804401d8ab274ade
build_command: php artisan view:cache
build_exit_code: 0
build_output_hash: sha256:80cef5dc038a68892a5d31fa8584274edd0149521457e27b6d0088e6a59289c9
```

## Verification Report

**Change**: live-class-materialization
**Version**: N/A
**Mode**: Standard (Strict TDD inactive)

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 14 |
| Tasks complete | 14 |
| Tasks incomplete | 0 |

### Build & Tests Execution

**Build**: Passed
```text
php artisan view:cache
  INFO  Blade templates cached successfully.
```

**Tests**: 205 passed / 0 failed / 0 skipped
```text
php artisan test
  Tests:    205 passed (558 assertions)
  Duration: 21.17s
```

**Coverage**: Not available / threshold: N/A

### Spec Compliance Matrix

| Requirement | Scenario | Test | Result |
|-------------|----------|------|--------|
| R1 | Meetings table schema | smoke: `php artisan db:table meetings` | COMPLIANT |
| R1 | N meetings per class allowed | `MeetingResourceTest > n meetings per class allowed` | COMPLIANT |
| R1 | Cascade delete class removes meetings | `MeetingResourceTest > cascade delete removes meetings when class is deleted` | COMPLIANT |
| R2 | Fillable mass-assigns all 6 fields | `MeetingResourceTest > fillable mass-assigns all fields` | COMPLIANT |
| R2 | classroom() relationship resolves | `MeetingResourceTest > classroom relationship resolves` | COMPLIANT |
| R2 | upcoming scope returns only future meetings | `MeetingResourceTest > upcoming scope returns meetings scheduled at or after now` | COMPLIANT |
| R2 | past scope returns only past meetings | `MeetingResourceTest > past scope returns meetings scheduled before now` | COMPLIANT |
| R2 | live scope returns only url-set meetings within window | `MeetingResourceTest > live scope returns meetings within live window with url set` | COMPLIANT |
| R2 | isLive() boundaries | `MeetingResourceTest > isLive returns ...` (exact, +14, +16, -16, no-url) | COMPLIANT |
| R2 | isPast() logic | `MeetingResourceTest > isPast returns true when scheduled_at is before now` | COMPLIANT |
| R3 | Teacher query scope | `MeetingResourceTest > teacher query scope shows only their own meetings` | COMPLIANT |
| R3 | Admin query scope | `MeetingResourceTest > admin sees all meetings` | COMPLIANT |
| R3 | Cross-teacher access | `MeetingResourceTest > cross-teacher access returns empty query` | COMPLIANT |
| R3 | ViewMeeting shows details with Join button if applicable | (none found) | UNTESTED |
| R4/R3 | Join Action enabled only when live + url set | `MeetingResourceTest > isLive ...` (via model, reused by visible callback) | PARTIAL |
| R5 | Past badge on old meetings | (none found) | UNTESTED |
| R3 | meeting_url validation rejects non-URL | (none found) | UNTESTED |
| R3 | Missing meeting_url hides Join button | `MeetingResourceTest > isLive returns false when meeting_url is null even within window` | COMPLIANT |
| R6 | ClassResource meetings-count badge | smoke: `ClassResource::getEloquentQuery()->withCount('meetings')` + badge column | COMPLIANT |
| R7 | Dashboard lists upcoming meetings | `StudentDashboardTest > dashboard shows upcoming meetings from subscribed classes` | COMPLIANT |
| R7 | Dashboard ordered by scheduled_at ASC | `StudentDashboardTest > dashboard shows next 5 meetings ordered by scheduled_at asc` | COMPLIANT |
| R7 | Dashboard limits to 5 | `StudentDashboardTest > dashboard shows next 5 meetings ordered by scheduled_at asc` | COMPLIANT |
| R7 | Dashboard empty state | `StudentDashboardTest > dashboard shows empty state when no upcoming meetings` | COMPLIANT |
| R7 | Dashboard "Live now!" indicator | `StudentDashboardTest > dashboard shows live now indicator for meetings within live window` | COMPLIANT |
| R7 | Dashboard Join button for live meetings | `StudentDashboardTest > dashboard shows join button for live meetings with url set` | COMPLIANT |
| R7 | Dashboard excludes past meetings | `StudentDashboardTest > dashboard does not show past meetings in live section` | COMPLIANT |
| R7 | Dashboard auth + role:STUDENT | `StudentDashboardTest > dashboard denies non-STUDENT roles` | COMPLIANT |
| R7 | Dashboard subscription isolation | `StudentDashboardTest > dashboard does not show meetings from unsubscribed classes` | COMPLIANT |
| MR-teacher-class-management | ClassResource badge shows count | `ClassResource.php` query + `BadgeColumn::make('meetings_count')` | COMPLIANT |
| MR-student-class-subscription | Dashboard live section exists | `Dashboard.php` + `dashboard.blade.php` | COMPLIANT |

**Compliance summary**: 25/33 scenarios compliant (3 critical untested + 1 partial)

### Correctness (Static Evidence)

| Requirement | Status | Notes |
|-------------|--------|-------|
| R1 Meetings table | Implemented | `database/migrations/2026_07_31_210000_create_meetings_table.php` creates id, class_id FK cascade, title, scheduled_at, duration_minutes default 60, meeting_url nullable, agenda nullable, timestamps, index on scheduled_at. |
| R2 Meeting model fillable | Implemented | `app/Models/Meeting.php` lines 10-11: Fillable attribute covers all 6 fields; casts scheduled_at and duration_minutes. |
| R2 classroom() relationship | Implemented | `app/Models/Meeting.php` lines 27-30: belongsTo SchoolClass with class_id foreign key. |
| R2 upcoming/past/live scopes | Implemented | `app/Models/Meeting.php` lines 35-56: scopeUpcoming, scopePast, scopeLive match spec. |
| R2 isLive()/isPast() | Implemented | `app/Models/Meeting.php` lines 62-75: mirror scope logic. |
| R3 MeetingResource query scope | Implemented | `app/Filament/Resources/MeetingResource.php` lines 37-43: teacher sees own, admin sees all after the `role !== 'ADMIN'` fix. |
| R3 MeetingResource form | Partially implemented | `app/Filament/Resources/MeetingResource.php` lines 49-79: all fields present and correctly typed. However, the `class_id` Select options are always scoped to `teacher_id = Auth::id()` with no admin bypass, so an admin sees an empty Select and cannot schedule a meeting for any class. |
| R3 MeetingResource table | Partially implemented | `app/Filament/Resources/MeetingResource.php` lines 82-134: title, class, scheduled, duration, Join Action present. **Missing**: the gray "Past" badge column required by R3/R5. |
| R4 Join Action visibility | Implemented | `app/Filament/Resources/MeetingResource.php` lines 113-119: Action uses `->visible(fn => $record->isLive())` and opens URL in new tab. |
| R5 Past badge | Not implemented | No past-state badge exists on the MeetingResource table. |
| R6 ClassResource badge | Implemented | `app/Filament/Resources/ClassResource.php` lines 30-35 and 75-77: `withCount('meetings')` + `BadgeColumn::make('meetings_count')`. |
| R7 Student dashboard section | Implemented | `app/Livewire/Dashboard.php` lines 44-56 and `resources/views/livewire/dashboard.blade.php` lines 200-232: query uses `now()->subMinutes(15)` lower bound, orders by scheduled_at, limits to 5, shows title/class/time, "Live now!" indicator, conditional Join button, and empty state. |
| R7 Dashboard auth | Implemented | Route middleware `auth` + `role:STUDENT` already existed; tests confirm 403 for TEACHER. |
| R8 Tests | Partially implemented | MeetingResourceTest covers scopes, model methods, cascade, fillable, and N-per-class, but does not cover form validation, table badge rendering, or admin form select behavior. StudentDashboardTest covers all dashboard scenarios. |

### Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| D1 Teacher scoping via classroom.teacher_id | Yes | `MeetingResource::getEloquentQuery()` uses `whereHas('classroom', fn => teacher_id)`. |
| D2 Admin override with `unless(role === 'ADMIN')` | Yes | Implemented as `when(role !== 'ADMIN', ...)`, equivalent. |
| D3 Join-window single source of truth | Yes | `Meeting::scopeLive()` and `Meeting::isLive()` are reused by table Action and Blade. |
| D4 Dashboard query shape | Yes | `subscribedClasses()->with(['meetings' => ...])` with limit and ordering. |
| D5 Past badge vs scheduled_at column | No | Design calls for a separate past badge; implementation omits it entirely. |
| D6 ViewMeeting with inline Infolist + Join Action | No | `ViewMeeting.php` is a bare `ViewRecord` stub; no custom Infolist or Join Action. |

### Pre-existing Bugs Caught and Fixed During Apply

1. **Admin query scope was unconditionally filtered before the ADMIN check** — Fixed in `MeetingResource::getEloquentQuery()` (`app/Filament/Resources/MeetingResource.php` lines 39-42). The teacher filter is now applied only when the authenticated user is not an admin. Confirmed by `MeetingResourceTest > admin sees all meetings`.
2. **`SchoolClass::meetings()` needed explicit `class_id` foreign key** — Fixed in `app/Models/SchoolClass.php` lines 46-49. The relationship explicitly uses `hasMany(Meeting::class, 'class_id')`, matching the `exams()` pattern.
3. **Dashboard query used `now()` instead of `now()->subMinutes(15)`** — Fixed in `app/Livewire/Dashboard.php` lines 47-48. The lower bound includes meetings within the live window so students see currently-live classes.

### Issues Found

**CRITICAL**:
1. Admin cannot schedule meetings via the Filament form. `MeetingResource` form's `class_id` Select always filters options to `SchoolClass::where('teacher_id', Auth::id())` (`app/Filament/Resources/MeetingResource.php` line 51). Because admins do not own classes, the Select is empty and the spec scenario "Admin schedules for any class" is not satisfied. Fix: scope the Select options by role, e.g. `when(Auth::user()?->role !== 'ADMIN', fn ($q) => $q->where('teacher_id', Auth::id()))`.
2. Missing "Past" badge on the MeetingResource table. Spec R3 explicitly lists "Past badge" in the table columns and R5 defines the gray "Past" badge for `scheduled_at < now()`. The table currently has no such badge (`app/Filament/Resources/MeetingResource.php` lines 85-106). Fix: add a `TextColumn::make('is_past')` or computed column with `->badge()` and gray color when `scheduled_at < now()`.
3. Spec scenario "meeting_url validation rejects non-URL" is untested. While the form schema does define `TextInput::make('meeting_url')->url()` (`app/Filament/Resources/MeetingResource.php` lines 70-73), there is no runtime test proving the validation rule rejects invalid input. Fix: add a Pest test that posts an invalid URL to `CreateMeeting` and asserts validation failure.

**WARNING**:
1. `ViewMeeting` is a bare `ViewRecord` stub (`app/Filament/Resources/MeetingResource/Pages/ViewMeeting.php`). The design D6 calls for a custom Infolist and conditional Join Action. The current page will render the form fields read-only but does not provide the Join button on the detail view.
2. Teacher/admin form behavior (teacher can schedule own class, cannot schedule foreign class) is implemented via Select options but is not exercised by runtime tests. Only query-scope read access is tested.

**SUGGESTION**:
1. Consider adding a dedicated Past badge test alongside the implementation to close the R5 scenario gap.
2. Consider whether the dashboard "Live now!" indicator should render for all meetings within the live window regardless of whether `meeting_url` is set; current code ties the indicator to `isLive()`, which also requires a URL.

### Verdict

**FAIL**

The change is functionally solid on the data layer, model scopes, dashboard, and test suite (205/205 tests pass). However, three CRITICAL issues block archive: the MeetingResource `class_id` Select prevents admins from scheduling meetings for any class; the required "Past" badge is missing from the MeetingResource table; and the URL-validation scenario lacks a covering test. Fix and re-verify before proceeding to `sdd-archive`.
