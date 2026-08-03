# Tasks: iCalendar (.ics) Export

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~260 (IcalBuilder 80 + Controller 40 + Test 100 + blade view 5 + route 3 + README 30) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Full ical-export feature | Single PR | `vendor/bin/pest tests/Feature/IcalExportTest.php` | `php artisan serve` → GET /meetings/{id}/ics as subscribed student | Delete 3 new files + revert 3 modified — no data loss |

## Phase 1: Foundation — IcalBuilder Service

- [x] 1.1 Create `app/Services/IcalBuilder.php` with class skeleton, `escapeIcalText()` private helper (escapes `\`, `;`, `,`, `\n` per RFC 5545), and `build(Meeting $meeting): string` stub returning minimal `BEGIN:VCALENDAR`/`END:VCALENDAR` with `VERSION:2.0` and `PRODID:-//online-exam-submission//ical-export//EN`. **Dependencies**: Meeting model (exists). **Verify**: `php -l app/Services/IcalBuilder.php` passes syntax check; `Test-Path app/Services/IcalBuilder.php` returns `True`.

- [x] 1.2 Implement full `build()` method: `UID:meeting-{id}@online-exam-submission.test`, `DTSTART`/`DTEND` in UTC `YYYYMMDDTHHMMSSZ` (DTEND = scheduled_at + (duration_minutes ?? 60)), `SUMMARY`/`DESCRIPTION`/`LOCATION` escaped via `escapeIcalText`, `ORGANIZER;CN={teacher.name}:mailto:{teacher.email}` via `$meeting->classroom->teacher`. **Dependencies**: 1.1. **Verify**: tinker test `(new App\Services\IcalBuilder)->build(App\Models\Meeting::with('classroom.teacher')->first())` returns string containing all 7 fields.

- [x] 1.3 Unit-smoke the builder edge cases via tinker: meeting with null `duration_minutes` yields DTEND = scheduled_at + 60 min; meeting with null `agenda`/`meeting_url` yields empty DESCRIPTION/LOCATION without errors; output contains no `RRULE` string. **Dependencies**: 1.2. **Verify**: each tinker assertion passes without exceptions.

## Phase 2: Controller + Route

- [x] 2.1 Create `app/Http/Controllers/IcalExportController.php` (extends `Controller`) with `export(Meeting $meeting, Request $request)` method: `abort_if(!$meeting->classroom->students()->where('users.id', Auth::id())->exists(), 403)` subscription check, then `$ics = app(IcalBuilder::class)->build($meeting)`, return `response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="meeting-' . $meeting->id . '.ics"'])`. **Dependencies**: 1.2. **Verify**: `php -l app/Http/Controllers/IcalExportController.php` passes.

- [x] 2.2 Add `use App\Http\Controllers\IcalExportController;` import and `Route::get('/meetings/{meeting}/ics', [IcalExportController::class, 'export'])->name('meetings.ics')->middleware(['auth', 'role:student']);` to `routes/web.php` inside the existing student middleware group (after line 36, before the closing `});`). Use route-model binding on `{meeting}`. **Dependencies**: 2.1. **Verify**: `php artisan route:list --name=meetings.ics` shows `GET` method, `meetings.ics` name, `auth` and `role:student` middleware.

- [x] 2.3 Smoke the endpoint manually: start artisan serve, log in as a subscribed student, visit `/meetings/{id}/ics`, confirm browser downloads a `.ics` file. Then test with unauthenticated (redirect to login) and non-student (403). **Dependencies**: 2.2. **Verify**: downloaded file opens in a calendar client or shows valid `VCALENDAR` text.

## Phase 3: Dashboard Integration

- [x] 3.1 Add a "Download .ics" link to `resources/views/livewire/dashboard.blade.php` inside each meeting card in the "Próximas clases en vivo" section (line ~228, after `@endif` of the live-block). Use `route('meetings.ics', $meeting)` with inline link style (`color:#2563eb`, `font-size:0.8125rem`, `text-decoration:none`) displayed below the meeting meta line. **Dependencies**: 2.2. **Verify**: `Get-Content resources/views/livewire/dashboard.blade.php | Select-String 'meetings.ics'` finds the route reference.

- [x] 3.2 Verify dashboard renders the link: log in as a subscribed student, visit `/dashboard`, confirm each meeting card under "Próximas clases en vivo" shows the "Download .ics" link with a valid `href`. **Dependencies**: 3.1. **Verify**: browser DevTools shows correct `href` pointing to `/meetings/{id}/ics`.

## Phase 4: Pest Tests

- [x] 4.1 Create `tests/Feature/IcalExportTest.php` with Pest v4 skeleton: `uses(RefreshDatabase::class)` trait, `beforeEach` seeding a teacher, student, class, class_user pivot, and meeting. Add auth tests: `it('redirects guest to login')` (`$this->get(route('meetings.ics', $meeting))->assertRedirect(route('login'))`) and `it('denies non-student role')` (`actingAs($teacher)->get(...)->assertForbidden()`). **Dependencies**: 2.2. **Verify**: `vendor/bin/pest tests/Feature/IcalExportTest.php --filter="redirects guest|denies non-student"` passes.

- [x] 4.2 Add subscription test: `it('denies unsubscribed student')` — actingAs a student NOT in `class_user` for the meeting's class, assert 403. **Dependencies**: 4.1. **Verify**: `vendor/bin/pest tests/Feature/IcalExportTest.php --filter="denies unsubscribed"` passes.

- [x] 4.3 Add happy-path test: `it('returns valid ics for subscribed student')` — actingAs subscribed student, assert status 200, `Content-Type: text/calendar`, `Content-Disposition` with correct filename, body contains `UID:meeting-{id}@online-exam-submission.test`, `DTSTART`, `DTEND` (90 min span), `SUMMARY:Algebra Review`, `DESCRIPTION:Review chapters 1-3`, `LOCATION:https://meet.example.com/abc`, `ORGANIZER;CN=Ana Pérez:mailto:ana@example.com`. **Dependencies**: 4.1. **Verify**: `vendor/bin/pest tests/Feature/IcalExportTest.php --filter="valid ics"` passes.

- [x] 4.4 Add edge-case tests: `it('defaults null duration to 60 minutes')` (DTEND = scheduled_at + 60 min), `it('handles null agenda and meeting_url')` (body contains `DESCRIPTION:` with empty value, `LOCATION:` with empty value, no 500 error), `it('contains no RRULE')` (`assertDontSee('RRULE')`). **Dependencies**: 4.3. **Verify**: `vendor/bin/pest tests/Feature/IcalExportTest.php --filter="null duration|null agenda|no RRULE"` passes.

- [x] 4.5 Add dashboard test: `it('renders download ics link on dashboard')` — actingAs student with meetings, `$this->get(route('dashboard'))->assertSee('Download .ics')->assertSee(route('meetings.ics', $meeting))`. **Dependencies**: 3.1, 4.1. **Verify**: `vendor/bin/pest tests/Feature/IcalExportTest.php --filter="dashboard"` passes.

- [x] 4.6 Run full IcalExport test suite: `vendor/bin/pest tests/Feature/IcalExportTest.php` — all 7 scenarios pass (guest redirect, teacher 403, unsubscribed 403, happy path 200, null-duration default, null-agenda/url, dashboard link). **Dependencies**: 4.1–4.5. **Verify**: terminal shows `Tests: 7 passed`.

- [x] 4.7 Run complete test suite: `vendor/bin/pest` — confirm 0 regressions against the existing ~218 tests. **Dependencies**: 4.6. **Verify**: terminal shows all tests passing with no failures or errors.

## Phase 5: Documentation + Final Smoke

- [x] 5.1 Add "iCalendar Export" section to `README.md` after "Recurring meetings": student-side download flow (dashboard → click "Download .ics" → calendar client imports), .ics field list (UID, DTSTART, DTEND, SUMMARY, DESCRIPTION, LOCATION, ORGANIZER), and deferred items (RRULE, per-class aggregate feed, `webcal://` subscription URL, VALARM reminders, email reminders). **Dependencies**: 4.6. **Verify**: `Get-Content README.md | Select-String 'iCalendar Export'` returns match.

- [x] 5.2 Final smoke: `php artisan route:list --name=meetings.ics` confirms route, `vendor/bin/pest` all green, `php artisan optimize:clear` clears cache without errors. **Dependencies**: 5.1. **Verify**: all three commands exit successfully.
