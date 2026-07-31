# Tasks: Live Class Materialization

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 300 (250–350) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Data layer + Filament resource + dashboard + tests + docs | Single PR | `vendor/bin/pest --filter="MeetingResourceTest|StudentDashboardTest"` | `php artisan route:list \| Select-String -Pattern "meeting"` | Drop meetings table (`migrate:rollback`) + revert 6 modified files |

## Phase 1: Data Layer

- [x] 1.1 Create migration `database/migrations/*_create_meetings_table.php` with columns: id, class_id FK→classes onDelete('cascade'), title(255), scheduled_at(datetime), duration_minutes(int default 60), meeting_url(nullable text), agenda(nullable text), timestamps. **Verify**: `Test-Path` confirms file; `php artisan migrate:status` shows pending migration.
- [x] 1.2 Run `php artisan migrate`. **Verify**: `php artisan migrate:status` shows migration "Ran"; `php artisan db:show` lists `meetings` table.
- [x] 1.3 Create `app/Models/Meeting.php` — `#[Fillable]` 6 fields; `casts(): ['scheduled_at'=>'datetime','duration_minutes'=>'integer']`; `classroom(): BelongsTo`; scopes `upcoming` (≥now), `past` (<now), `live` (url set + within ±15 min); `isLive(): bool`. Follow `Exam` model style. **Verify**: `php artisan tinker --execute="echo class_exists(App\\Models\\Meeting::class) ? 'OK' : 'FAIL';"` returns OK.
- [x] 1.4 Add `meetings(): HasMany` to `app/Models/SchoolClass.php` (follows `exams()` pattern). **Verify**: `php artisan tinker --execute="echo method_exists(App\\Models\\SchoolClass::class,'meetings')?'OK':'FAIL';"` returns OK.

## Phase 2: Filament MeetingResource

- [x] 2.1 Create `app/Filament/Resources/MeetingResource.php` — model `Meeting::class`; `getEloquentQuery()` teacher-scoped via `when(role !== 'ADMIN')` per `ClassReportResource` pattern; form: class_id Select (teacher-scoped), title TextInput, scheduled_at DateTimePicker, duration_minutes numeric+suffix('min'), meeting_url url, agenda RichEditor; table columns: title, classroom.title, scheduled_at dateTime, duration_minutes BadgeColumn("min"); Join Action (opens url in new tab, `->visible(fn($r)=>$r->isLive())`); Past Badge (gray when `< now()`). **Verify**: `php artisan tinker --execute="echo class_exists(App\\Filament\\Resources\\MeetingResource::class)?'OK':'FAIL';"` returns OK.
- [x] 2.2 Create `app/Filament/Resources/MeetingResource/Pages/ListMeetings.php` extending `ListRecords`. **Verify**: `Test-Path` confirms; `php artisan route:list | Select-String "meeting"` lists index route.
- [x] 2.3 Create `app/Filament/Resources/MeetingResource/Pages/CreateMeeting.php` extending `CreateRecord`. **Verify**: `Test-Path` + route:list shows create route.
- [x] 2.4 Create `app/Filament/Resources/MeetingResource/Pages/EditMeeting.php` extending `EditRecord`. **Verify**: `Test-Path` + route:list shows edit route.
- [x] 2.5 Create `app/Filament/Resources/MeetingResource/Pages/ViewMeeting.php` extending `ViewRecord` with `Infolist`: title, classroom, scheduled_at, duration, agenda, conditional Join Action. **Verify**: `Test-Path` + route:list shows view route.
- [x] 2.6 Modify `app/Filament/Resources/ClassResource.php` — add `->withCount('meetings')` to `getEloquentQuery()`; add `BadgeColumn::make('meetings_count')->label('Meetings')` after invitation_code column. **Verify**: route:list unchanged; `php artisan tinker --execute="echo SchoolClass::withCount('meetings')->first()?->meetings_count??'OK';"` returns 0 or count.

## Phase 3: Student Dashboard

- [x] 3.1 Modify `app/Livewire/Dashboard.php` — query `$upcomingMeetings` from `subscribedClasses()->with(['meetings'=>fn($q)=>$q->where('scheduled_at', '>=', now()->subMinutes(15))->orderBy('scheduled_at')->limit(5)])`; pass to view. **Verify**: `vendor/bin/pest --filter=StudentDashboardTest` — ALL existing tests still pass (additive-only change).
- [x] 3.2 Modify `resources/views/livewire/dashboard.blade.php` — add "Próximas clases en vivo" section after completed exams: loop `$upcomingMeetings` cards with title, class name, `diffForHumans()`+`format('M j, g:i A T')`, "Live now!" indicator (`isLive()`), Join button (`target="_blank"`, url set + live), empty state "No hay clases en vivo programadas…". Exclude past. **Verify**: `php artisan view:cache` succeeds (no Blade errors).

## Phase 4: Pest Tests

- [x] 4.1 Create `tests/Feature/MeetingResourceTest.php` with `RefreshDatabase`, `actingAs`, `Carbon::setTestNow()`. Cover: teacher CRUD scoping, admin sees all, cross-teacher 403, form url validation, join window boundaries (-16/-15/0/+14/+16), past badge visibility, cascade delete, scope queries (upcoming/past/live), fillable fields, N meetings per class, ViewMeeting display. **Verify**: `vendor/bin/pest --filter=MeetingResourceTest` — all new tests pass.
- [x] 4.2 Extend `tests/Feature/StudentDashboardTest.php` — add tests: upcoming meetings ordered by scheduled_at ASC, limit 5 enforced, empty state text, auth gate (403 for TEACHER), subscription isolation, "Live now!" indicator rendered, Join button visible when live+url set. **Verify**: `vendor/bin/pest --filter=StudentDashboardTest` — ALL tests (existing + new) pass.

## Phase 5: Documentation + Final Smoke

- [x] 5.1 Add "Live class materialization" section to `README.md` after Reports: feature summary, deferred items (recurring meetings, iCal export, recording/replay, attendance tracking, email notifications). **Verify**: `Get-Content README.md | Select-String "Live class materialization"` returns a match.
- [x] 5.2 Run full test suite: `vendor/bin/pest`. **Verify**: All tests pass; baseline ~181 tests, expect ~200+; zero failures or errors.
- [x] 5.3 Verify Filament routes: `php artisan route:list | Select-String "meeting"`. **Verify**: 4 meeting routes (index, create, edit, view) listed; no duplicate route names.
