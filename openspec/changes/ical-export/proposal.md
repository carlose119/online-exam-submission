# Proposal: iCalendar (.ics) Export

## Intent

Students see upcoming live meetings on `/dashboard` but cannot add them to their personal calendar (Google Calendar, Apple Calendar, Outlook). They must copy the time and meeting_url by hand, which is error-prone and detaches the LMS from the workflow students actually live in. `ical-export` lets a subscribed student download a standard RFC 5545 `.ics` file for a single meeting so their calendar client imports it (UID, DTSTART, DTEND, SUMMARY, DESCRIPTION, LOCATION, ORGANIZER). This extends — does not replace — the canonical `live-class-meeting-management` capability (the `meetings` table and `Meeting` model come from it).

## Scope

### In Scope
- 1 new service `app/Services/IcalBuilder.php` with a single public method `build(Meeting $meeting): string` returning an RFC 5545 `VCALENDAR`/`VEVENT` string. Fields: `UID` = `meeting-{id}@online-exam-submission.test`; `DTSTART` = `scheduled_at` as `YYYYMMDDTHHMMSSZ` (UTC); `DTEND` = `scheduled_at + duration_minutes` (default 60 if null) as `YYYYMMDDTHHMMSSZ`; `SUMMARY` = `title`; `DESCRIPTION` = `agenda` (plain text); `LOCATION` = `meeting_url`; `ORGANIZER` = `CN={teacher name}:mailto:{teacher email}` via `$meeting->classroom->teacher`. Wrappers: `VERSION:2.0`, `PRODID:-//online-exam-submission//ical-export//EN`.
- 1 new controller `app/Http/Controllers/IcalExportController.php` with `export(Meeting $meeting, Request $request)` returning a download response: `Content-Type: text/calendar; charset=utf-8`, `Content-Disposition: attachment; filename="meeting-{id}.ics"`.
- 1 new route `routes/web.php`: `GET /meetings/{meeting}/ics` named `meetings.ics` behind `auth` + `role:student` + subscription check (user is subscribed to `$meeting->classroom` via `class_user`).
- 1 new file `tests/Feature/IcalExportTest.php` (4–6 Pest scenarios): auth gate, subscription isolation, .ics content (all 7 fields), Content-Type/Content-Disposition headers, filename, null-`duration_minutes` default, null-`agenda`/`meeting_url` handling.
- 1 small modification to `app/Livewire/Dashboard.php` + its Blade view: a "Download .ics" link on each meeting card in "Próximas clases en vivo" pointing to `route('meetings.ics', ['meeting' => $meeting->id])`.
- README "iCalendar export" section after "Recurring meetings": student-side download flow, .ics field list, deferred items.
- 0 new composer dependencies; 0 schema changes.

### Out of Scope
- Subscription URL (`webcal://`) for per-class .ics auto-feed.
- Per-class aggregate `.ics` (all meetings of a class in one file).
- RRULE support for recurring series — this slice exports ONE meeting instance only.
- Calendar invitations / RSVPs to external platforms (Google Meet/Zoom invite APIs).
- Email reminders for meetings.
- Attendees, reminders (VALARM), attachments in the .ics.

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `live-class-meeting-management`: the student side of the capability gains an iCalendar export endpoint and a "Download .ics" dashboard affordance. The `meetings` table, `Meeting` model, scopes, Filament `MeetingResource`, recurring-meetings behaviour, and existing dashboard "Próximas clases en vivo" listing/Join flow are unchanged.

## Approach

A pure-PHP string builder (no Composer iCal library): `IcalBuilder::build()` reads the meeting's standard columns plus `classroom.teacher` and emits a single `VEVENT` inside a `VCALENDAR`. Route-controller boundary handles auth + subscription (reuse the `role:student` alias and a `class_user` pivot check matching the student-profile pattern), then returns the built string as a streamed download with `text/calendar` headers. Time stays UTC in the file (matches existing storage); the student's calendar client renders in local time.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/IcalBuilder.php` | New | RFC 5545 string builder, `build(Meeting): string`. |
| `app/Http/Controllers/IcalExportController.php` | New | `export()` returns `.ics` download response. |
| `routes/web.php` | Modified | Adds `GET /meetings/{meeting}/ics` named `meetings.ics` behind `auth` + `role:student` + subscription check. |
| `app/Livewire/Dashboard.php` + Blade view | Modified | "Download .ics" link per meeting card. |
| `tests/Feature/IcalExportTest.php` | New | Pest coverage for auth, subscription, .ics content, headers, filename, null-duration default. |
| `README.md` | Modified | New "iCalendar export" section. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Basic scope (per-meeting `.ics`, not per-class feed or subscription URL) — students wanting auto-sync must manually re-download. | High | Document explicitly as deferred; per-meeting download already covers the "add to calendar" need. |
| Subscription URL (`webcal://`) and per-class aggregate `.ics` deferred — no auto-refresh in calendar clients. | Med | Out of scope this slice; design leaves `IcalBuilder::build()` reusable for a future feed endpoint. |
| RRULE support deferred — recurring series export as one `.ics` with `RRULE` is NOT delivered; each instance is a separate download. | Med | Document; future change can extend `IcalBuilder` to emit `RRULE` from `recurrence_rule` JSON. |
| Basic iCal content only — no attendees, VALARM reminders, or attachments — power users may want more. | Low | Out of scope; RFC 5545 core fields cover 95% of calendar imports. |
| `IcalBuilder` correctness vs. RFC 5545 (line folding, escaping of commas/semicolons in text fields) — bad output silently rejected by strict clients. | Med | Test `.ics` output byte-by-byte against expected fixtures; escape `,` `;` `\n` in `SUMMARY`/`DESCRIPTION`. |
| Subscription check duplicates dashboard logic — drift risk between the two. | Low | Reuse the same `class_user` pivot query shape as Dashboard; cover with a shared Pest scenario. |

## Rollback Plan

1. Revert `routes/web.php` (remove the `meetings.ics` route) — endpoint 404s, dashboard link breaks harmlessly.
2. Revert the Dashboard Blade view "Download .ics" link (or leave the link; it 404s once the route is gone).
3. Delete `app/Services/IcalBuilder.php`, `app/Http/Controllers/IcalExportController.php`, `tests/Feature/IcalExportTest.php`.
4. Revert README "iCalendar export" section.
5. No migration to reverse (0 schema changes); no data loss; existing meetings, dashboard, and Join flow are untouched.

## Dependencies

- Laravel 13.19.0, Filament v5.6.8, Livewire v4.3.3, MariaDB 10.11.9, Pest v4.7.5 — all already installed (0 new composer deps).
- Canonical spec `live-class-meeting-management` (the `meetings` table, `Meeting` model, learning of `class_user` pivot, `role:student` middleware alias, student Dashboard) — this change is a delta on it.
- `recurring-meetings` (archived) provides `recurrence_rule`/`parent_id` columns — untouched; this slice exports a single instance regardless of parent/child status.

## Success Criteria

- [ ] Authenticated student subscribed to the meeting's class can `GET /meetings/{meeting}/ics` and receive a valid `.ics` download.
- [ ] Unauthenticated user and non-subscribed student get 403.
- [ ] `.ics` content contains `UID`, `DTSTART`, `DTEND`, `SUMMARY`, `DESCRIPTION`, `LOCATION`, `ORGANIZER` with correct values and UTC timestamps.
- [ ] `duration_minutes = null` resolves to 60 min in `DTEND`.
- [ ] Response headers are `Content-Type: text/calendar; charset=utf-8` and `Content-Disposition: attachment; filename="meeting-{id}.ics"`.
- [ ] Dashboard "Próximas clases en vivo" shows a "Download .ics" link per meeting card.
- [ ] `tests/Feature/IcalExportTest.php` passes alongside the existing suite.
- [ ] README "iCalendar export" section documents the flow and deferred items.