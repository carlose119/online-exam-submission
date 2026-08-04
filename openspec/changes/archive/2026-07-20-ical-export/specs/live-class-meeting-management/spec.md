# Delta for live-class-meeting-management

This is a DELTA on the existing `live-class-meeting-management` capability. The canonical spec at `openspec/specs/live-class-meeting-management/spec.md` contains 17 requirements (R1–R15 plus 2 cross-capability modifications). Those 17 are preserved; this delta ADDS the following new requirements for the ical-export change.

## ADDED Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| R16 | IcalBuilder Service | `app/Services/IcalBuilder.php` with `build(Meeting): string`. Returns RFC 5545 `VCALENDAR`/`VEVENT` string. Fields: UID, DTSTART, DTEND, SUMMARY, DESCRIPTION, LOCATION, ORGANIZER. Wraps: VERSION:2.0, PRODID. |
| R17 | iCalendar Export Endpoint | `GET /meetings/{meeting}/ics` named `meetings.ics` in `routes/web.php`. Behind `auth`, `role:student`, and subscription check (`class_user` pivot). Returns download with `Content-Type: text/calendar; charset=utf-8` and `Content-Disposition: attachment; filename="meeting-{id}.ics"`. |
| R18 | iCalendar Content Format | UID=`meeting-{id}@online-exam-submission.test`. DTSTART/DTEND in UTC (`YYYYMMDDTHHMMSSZ`). DTEND = `scheduled_at + duration_minutes` (default 60 if null). SUMMARY=`title`, DESCRIPTION=`agenda` (plain text), LOCATION=`meeting_url`. ORGANIZER=`CN={teacher name}:mailto:{teacher email}` via `$meeting->classroom->teacher`. |
| R19 | Dashboard iCal Export Link | Student dashboard "Próximas clases en vivo" SHALL display a "Download .ics" link on each meeting card pointing to `route('meetings.ics', ['meeting' => $meeting->id])`. |
| R20 | iCalendar Export Tests | `tests/Feature/IcalExportTest.php` with Pest scenarios: auth gate (guest→login, non-student→denied), subscription isolation (non-subscribed→403), all 7 .ics fields, Content-Type/Content-Disposition headers, null-`duration_minutes` default (60 min), null-`agenda`/`meeting_url` handling. |
| R21 | iCal Export Scope Boundaries | This slice SHALL export a single meeting as `.ics`. RRULE, per-class aggregate feed, `webcal://` subscription URL, VALARM reminders, and email reminders are deferred out of scope and MUST NOT be implemented. |

### Scenario: IcalBuilder builds valid iCalendar

- GIVEN a meeting with all fields populated and `classroom.teacher` loaded
- WHEN `IcalBuilder::build($meeting)` is called
- THEN output begins with `BEGIN:VCALENDAR`, contains `VERSION:2.0`, `PRODID:-//online-exam-submission//ical-export//EN`, and ends with `END:VCALENDAR`

### Scenario: IcalBuilder handles null duration

- GIVEN a meeting with `duration_minutes=null` and `scheduled_at`
- WHEN `IcalBuilder::build($meeting)` is called
- THEN DTEND equals `scheduled_at + 60 minutes` in UTC `YYYYMMDDTHHMMSSZ`

### Scenario: IcalBuilder handles null agenda and meeting_url

- GIVEN a meeting with `agenda=null` and `meeting_url=null`
- WHEN `IcalBuilder::build($meeting)` is called
- THEN DESCRIPTION and LOCATION appear with empty string values, no errors

### Scenario: Authenticated subscribed student downloads .ics

- GIVEN an authenticated student subscribed to the meeting's class via `class_user`
- WHEN `GET /meetings/{meeting}/ics` is requested
- THEN response is a valid .ics download with status 200

### Scenario: Guest redirected to login

- GIVEN an unauthenticated user
- WHEN `GET /meetings/{meeting}/ics` is requested
- THEN redirected to login

### Scenario: Non-student role denied

- GIVEN an authenticated teacher or admin
- WHEN `GET /meetings/{meeting}/ics` is requested
- THEN access denied (403) by `role:student` middleware

### Scenario: Non-subscribed student denied

- GIVEN an authenticated student NOT subscribed to the meeting's class
- WHEN `GET /meetings/{meeting}/ics` is requested
- THEN 403 denied

### Scenario: Response headers

- GIVEN an authenticated subscribed student
- WHEN `GET /meetings/{meeting}/ics` returns successfully
- THEN `Content-Type` is `text/calendar; charset=utf-8` AND `Content-Disposition` is `attachment; filename="meeting-{id}.ics"`

### Scenario: .ics contains all fields for a complete meeting

- GIVEN a meeting with title="Algebra Review", agenda="Review chapters 1-3", meeting_url="https://meet.example.com/abc", duration=90, teacher name="Ana Pérez", teacher email="ana@example.com"
- WHEN .ics is generated
- THEN UID=`meeting-{id}@online-exam-submission.test`, SUMMARY="Algebra Review", DESCRIPTION="Review chapters 1-3", LOCATION="https://meet.example.com/abc", ORGANIZER="CN=Ana Pérez:mailto:ana@example.com", DTSTART and DTEND in UTC timestamp format with 90-minute span

### Scenario: Dashboard shows .ics link per meeting card

- GIVEN an authenticated student with 3 upcoming meetings on `/dashboard`
- WHEN the "Próximas clases en vivo" section renders
- THEN each meeting card displays a "Download .ics" link

### Scenario: .ics contains no RRULE for non-recurring scope

- GIVEN any meeting exported via `IcalBuilder`
- WHEN .ics string is inspected
- THEN it MUST NOT contain the string `RRULE`
