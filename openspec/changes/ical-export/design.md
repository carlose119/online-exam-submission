# Design: iCalendar (.ics) Export

## Technical Approach

Extends `live-class-meeting-management` (R1-R15) with 6 new requirements (R16-R21). A pure-PHP `IcalBuilder` service constructs an RFC 5545 `VCALENDAR`/`VEVENT` string from a `Meeting` model. A single-action controller returns it as a download behind `auth` + `role:student` + an in-controller `class_user` pivot check. A dashboard link exposes the endpoint. Zero new Composer dependencies — Laravel 13 + Carbon handle datetime formatting. Zero schema changes — the `meetings` table and `class_user` pivot already exist.

## Architecture Decisions

### Decision: IcalBuilder as a standalone service (no Composer iCal library)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Composer iCal library (`spatie/icalendar-generator`, `eluceo/ical`) | Adds dependency, version churn, must match PHP 8.4; overkill for single-VEVENT export | Rejected |
| **Pure-PHP string builder in `app/Services/IcalBuilder`** | Manual RFC 5545 escaping needed; zero dependency cost; full control | **Chosen** |

**Rationale**: The proposal explicitly mandates 0 new composer dependencies. The builder emits one `VEVENT` per call — no recurrence, attendees, or alarms. A `build(Meeting): string` method with an `escapeIcalText` helper is under 40 lines.

### Decision: Subscription check inside the controller, not a dedicated middleware

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Dedicated middleware (e.g. `EnsureSubscribedToMeetingClass`) | Reusable; must re-query Meeting after route model binding; adds 1 class | Rejected |
| **`abort_if` inside `IcalExportController::export`** | Duplicates dashboard query shape; simpler; no new kernel registration | **Chosen** |

**Rationale**: The check is single-use — only this endpoint needs it. Extracting it now would create a class with 1 caller. If a second endpoint grows similar logic, extract then.

### Decision: `escapeIcalText` as a private helper on `IcalBuilder`

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Trait or standalone utility | Premature abstraction for 4 string replacements | Rejected |
| **Private method on `IcalBuilder`** | Self-contained; testable via `build()` output | **Chosen** |

**Rationale**: The escapes (newlines → `\n`, commas → `\,`, semicolons → `\;`, backslashes → `\\`) are only needed by `SUMMARY`, `DESCRIPTION`, and `LOCATION`. A private helper keeps the call-site clear.

## Data Flow

```
Dashboard Blade ──→ GET /meetings/{meeting}/ics
                        │
                        ▼
              IcalExportController::export()
                  │ auth (middleware)
                  │ role:student (middleware)
                  │ class_user check (in-controller)
                        │
                        ▼
              IcalBuilder::build($meeting)
                  │ reads Meeting + classroom.teacher
                  │ applies escapeIcalText to text fields
                  │ returns string
                        │
                        ▼
              Response: Content-Type + Content-Disposition
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/IcalBuilder.php` | **Create** | `build(Meeting): string` + `escapeIcalText(string): string` |
| `app/Http/Controllers/IcalExportController.php` | **Create** | `export(Meeting, Request): Response` — auth check, subscription check, calls builder, returns download |
| `tests/Feature/IcalExportTest.php` | **Create** | 7 Pest scenarios per delta spec R20 |
| `routes/web.php` | **Modify** | Add `GET /meetings/{meeting}/ics` named `meetings.ics` behind `auth` + `role:student` |
| `resources/views/livewire/dashboard.blade.php` | **Modify** | Add "Download .ics" link inside each meeting card |
| `README.md` | **Modify** | Add "iCalendar export" section after "Recurring meetings" |

## Interfaces / Contracts

```php
// app/Services/IcalBuilder.php
class IcalBuilder
{
    public function build(Meeting $meeting): string;
    // Returns VCALENDAR with single VEVENT:
    //   VERSION:2.0
    //   PRODID:-//online-exam-submission//ical-export//EN
    //   UID:meeting-{id}@online-exam-submission.test
    //   DTSTART:{scheduled_at as YYYYMMDDTHHMMSSZ}
    //   DTEND:{scheduled_at + (duration_minutes ?? 60) in UTC}
    //   SUMMARY:{title}
    //   DESCRIPTION:{agenda} — omitted if null
    //   LOCATION:{meeting_url}
    //   ORGANIZER;CN={teacher.name}:mailto:{teacher.email}

    private function escapeIcalText(string $value): string;
    // Escapes \  → \\, ; → \;, , → \,  , \n → \n
}
```

```php
// app/Http/Controllers/IcalExportController.php
class IcalExportController extends Controller
{
    public function export(Meeting $meeting, Request $request): Response;
    // Checks class_user pivot via $meeting->classroom->students()->where('users.id', Auth::id())->exists()
    // Returns response($ics, 200, [
    //   'Content-Type' => 'text/calendar; charset=utf-8',
    //   'Content-Disposition' => 'attachment; filename="meeting-{id}.ics"',
    // ])
}
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Auth gate: guest → redirect to login | `$this->get()` without `actingAs` → `assertRedirect` |
| Feature | Role gate: teacher → 403 | `actingAs(teacher)` → `assertForbidden` |
| Feature | Subscription gate: unsubscribed student → 403 | `actingAs(unsubscribedStudent)` → `assertForbidden` |
| Feature | Happy path: subscribed student → 200 + valid .ics | Assert all 7 fields, Content-Type, Content-Disposition, filename |
| Feature | Null `duration_minutes` → defaults to 60 min | Assert DTEND = scheduled_at + 60 min |
| Feature | Null `agenda` / `meeting_url` → no crash | Assert response still 200, DESCRIPTION absent |
| Feature | Dashboard renders "Download .ics" link | `actingAs(student)` → `assertSee('Download .ics')` + `assertSee(route('meetings.ics', …))` |

Strict TDD is **false** (per `config.yaml`). Tests are written AFTER implementation — `Pest v4.7.5` validates correctness.

## Threat Matrix

N/A — `GET /meetings/{meeting}/ics` is a read-only route returning a string-based download behind `auth` and `role:student` middleware. No shell commands, subprocesses, VCS/PR automation, executable-file classification, or process integration boundary exists.

## Migration / Rollout

No migration required (0 schema changes). No feature flags — the route and dashboard link coexist with existing meeting behavior. Rollback: remove route from `web.php`, remove dashboard link, delete the 3 new files. No data loss; existing meetings and dashboard are untouched.

## Open Questions

None — all technical decisions resolved in the proposal. The design is ready for task breakdown.
