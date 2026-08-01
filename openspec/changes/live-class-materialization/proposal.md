# Proposal: Live Class Materialization

## Intent

Teachers currently have classes, materials, and exams, but no way to schedule a live session. Students see subscribed classes on `/dashboard` but cannot tell when the next live class happens. This change introduces a `meetings` table and surfaces upcoming/live meetings so the platform delivers PRD §3.4's "links to live classes" intent — a teacher schedules a session and students join it when it goes live.

## Scope

### In Scope
- New `meetings` migration: id, class_id (FK → classes `onDelete('cascade')`), title, scheduled_at, duration_minutes (default 60), meeting_url, agenda, timestamps. N meetings per class (class_id NOT unique).
- New `Meeting` model: fillable, casts (scheduled_at→datetime, duration_minutes→integer), `classroom()` belongsTo, scopes `upcoming`/`past`/`live` (live = scheduled_at within ±15 min of `now()` adjusted by duration).
- New Filament `MeetingResource` (List/Create/Edit/View pages): teacher-scoped via `whereHas('classroom.teacher', …Auth::id())`; admin sees all; form + table with title, classroom, scheduled_at (sortable, `->dateTime()`), duration ("min" suffix), "Join" Action (link to meeting_url, new tab, enabled only when meeting_url set AND `now()` within ±15 min window), "Past" gray Badge for `scheduled_at < now()`.
- Extend `app/Livewire/Dashboard.php` with "Próximas clases en vivo" section: `subscribedClasses()->with(['meetings' => upcoming()->limit(5)])`, shows title + class + `diffForHumans()` / `format('M j, g:i A T')` + Join button + "Live now!" indicator; empty state "No hay clases en vivo programadas…".
- Add meetings-count badge to `ClassResource` table (`withCount('meetings')`).
- Tests: new `tests/Feature/MeetingResourceTest.php`; extend `tests/Feature/StudentDashboardTest.php`.
- README "Live class materialization" section.

### Out of Scope
- Recurring meetings (RRULE)/"every Monday 18:00".
- iCal `.ics` export; Google/Outlook `webcal://` subscription.
- Recording/replay, attendance tracking, in-meeting chat/Q&A.
- Email notifications (no mailer configured).
- Live meeting on the public `/clase/unirse/{code}` page (join flow stays separate).

## Capabilities

### New Capabilities
- `live-class-materialization`: Meeting scheduling (teacher) + upcoming/live surfacing (student), N meetings per class, Join window ±15 min, time-based Filament patterns.

### Modified Capabilities
- `teacher-class-management`: ClassResource table gains a meetings-count badge (display-only; no CRUD change).
- `student-class-subscription`: Student dashboard gains the "Próximas clases en vivo" section (additive; existing sections unchanged).

## Approach

Follow the Filament v5 `ClassResource`/`ClassReportResource` role-scoping pattern. Store `scheduled_at` in UTC; display via Carbon `diffForHumans()` + `format('M j, g:i A T')`. Live window computed in the query scope and reused by Filament Action `->visible()`/`->disabled()`. This is the project's first time-based table — establishes scope + display conventions for follow-ups.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/*_create_meetings_table.php` | New | Meetings table with class_id cascade FK. |
| `app/Models/Meeting.php` | New | Model, casts, scopes. |
| `app/Models/SchoolClass.php` | Modified | Add `meetings()` hasMany. |
| `app/Filament/Resources/MeetingResource.php` + Pages | New | Teacher/admin scheduling UI. |
| `app/Filament/Resources/ClassResource.php` | Modified | meetings-count badge. |
| `app/Livewire/Dashboard.php` | Modified | "Próximas clases en vivo" section. |
| `tests/Feature/MeetingResourceTest.php`, `StudentDashboardTest.php` | New/Modified | Pest coverage. |
| `README.md` | Modified | Usage + deferred items. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Time-zone display mismatch (UTC storage vs local display) | Med | Centralize Carbon formatting; never compare raw `scheduled_at` to user wall-clock; test with frozen `now()`. |
| "Join" window ±15 min edge cases (boundary, missing URL) | Med | Single source of truth in `Meeting::live()` scope; action `disabled()` unless `meeting_url` set; Pest cases on boundaries. |
| Deferred recurring meetings frustrate "every Monday" use | Med | Document as known gap; N-per-class still allows manual repetition. |
| Deferred iCal export limits external calendar use | Low | Document; future `calendar-integration` change. |
| Deferred email reminders (no mailer) | Low | Dashboard "Live now!" indicator is the sole reminder surface; documented. |
| Public join page unrelated to meeting scheduling | Low | Explicitly out of scope; join page unchanged. |

## Rollback Plan

Drop `meetings` table via rollback, remove `Meeting` model, `MeetingResource` + Pages, `SchoolClass::meetings()`, ClassResource badge, Dashboard section, and the two test files. The change is additive — reverting the migration restores prior state with no data dependency elsewhere.

## Dependencies

- `teacher-class-management` (ClassResource, SchoolClass teacher scope).
- `student-class-subscription` (Dashboard, `subscribedClasses()`).
- No new composer packages (Filament v5 + Livewire v4 + Pest v4 already installed).

## Success Criteria

- [ ] Teacher can create/list/edit/delete meetings for their own classes only; admin sees all.
- [ ] Cross-teacher meeting access returns 404; deleting a class cascades its meetings.
- [ ] "Join" Action enabled iff `meeting_url` set AND `now()` within ±15 min window.
- [ ] Student dashboard shows up to 5 upcoming meetings with formatted times; empty state when none.
- [ ] New + extended Pest tests pass within the 400-line authored-code budget.