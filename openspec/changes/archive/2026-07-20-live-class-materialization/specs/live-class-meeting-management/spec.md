# live-class-meeting-management Specification

## Purpose

Teachers schedule live meetings for classes; students see upcoming/live sessions on `/dashboard`. Introduces `meetings` table, `Meeting` model with time-based scopes (`upcoming`, `past`, `live`), Filament `MeetingResource`, and student dashboard "Próximas clases en vivo". UTC storage; ±15 min live window.

## Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| R1 | Meetings Table | id, class_id FK→classes cascade, title(255), scheduled_at(datetime), duration_minutes(int default 60), meeting_url(nullable string), agenda(nullable text), timestamps. class_id NOT unique. |
| R2 | Meeting Model | Fillable: 6 fields. Casts: scheduled_at→datetime, duration_minutes→integer. `classroom()` belongsTo SchoolClass. Scopes: `upcoming`(≥now()), `past`(<now()), `live`(now()∈[scheduled_at±15 min] AND url NOT NULL). |
| R3 | MeetingResource | Filament CRUD under /admin. Teacher: `whereHas('classroom.teacher',Auth::id())`. Admin: all. Form: class_id Select(role-scoped), title, scheduled_at DateTimePicker, duration_minutes, meeting_url(url), agenda RichEditor. Table: title, classroom.title, scheduled_at(dateTime), duration_minutes("min"), "Join" Action, "Past" badge. ViewMeeting: read-only. |
| R4 | Join Action | Opens meeting_url in new tab. Visible ONLY when url IS NOT NULL AND `live()` scope true. |
| R5 | Past Badge | Gray "Past" on rows where scheduled_at < now(). |
| R6 | Class Badge | ClassResource table SHALL show `withCount('meetings')` badge. |
| R7 | Student Dashboard | "Próximas clases en vivo": 5 upcoming meetings from subscribed classes, ordered scheduled_at ASC. "Live now!" indicator + Join button (url set AND within ±15 min). Empty state. Excludes past. Auth + role:STUDENT. Subscription isolation. UTC storage. |
| R8 | Tests | MeetingResourceTest: scope, validation, join window, badge, cascade. StudentDashboardTest extended: ordering, empty, auth, isolation, indicator. Time frozen via `Carbon::setTestNow()`. |

### Scenario: Teacher schedules for own class

- GIVEN Teacher A class "Math"
- WHEN creates meeting
- THEN persisted with correct class_id

### Scenario: Teacher cannot schedule for foreign class

- GIVEN Teacher A filling form
- WHEN submits with foreign class_id
- THEN 403; Select only shows own classes

### Scenario: Admin schedules for any class

- GIVEN Admin at create form
- THEN all classes in Select; any class_id accepted

### Scenario: meeting_url validation rejects non-URL

- GIVEN form filled
- WHEN meeting_url="not-a-url"
- THEN url validation fails

### Scenario: Missing meeting_url hides Join button

- GIVEN live-window meeting, url=null
- WHEN table/ViewMeeting renders
- THEN no Join; "Join link not available"

### Scenario: Past badge on old meetings

- GIVEN meeting with scheduled_at < now()
- WHEN table renders
- THEN gray "Past" badge shown

### Scenario: Join enabled at exactly scheduled_at

- GIVEN url set, now()=scheduled_at
- THEN Join button enabled

### Scenario: Join enabled +14 min after start

- GIVEN url set, now()=scheduled_at+14 min
- THEN Join button enabled

### Scenario: Join disabled +16 min after start

- GIVEN url set, now()=scheduled_at+16 min
- THEN Join button disabled

### Scenario: Join disabled 16 min before start

- GIVEN url set, now()=scheduled_at−16 min
- THEN Join button disabled

### Scenario: upcoming scope

- GIVEN M1(scheduled_at=now()+1h), M2(scheduled_at=now()−1h)
- WHEN Meeting::upcoming()->get()
- THEN only M1

### Scenario: past scope

- GIVEN M1(scheduled_at=now()−1h), M2(scheduled_at=now()+1h)
- WHEN Meeting::past()->get()
- THEN only M1

### Scenario: live scope

- GIVEN A(±15 min,url set), B(±15 min,url null), C(outside window)
- WHEN Meeting::live()->get()
- THEN only A

### Scenario: classroom() relationship resolves

- GIVEN meeting belongsTo class "Math"
- WHEN $meeting->classroom
- THEN SchoolClass "Math"

### Scenario: fillable mass-assigns all fields

- GIVEN meeting creation
- WHEN all 6 fields set
- THEN all persisted

### Scenario: cascade delete class removes meetings

- GIVEN class "Math" with 3 meetings
- WHEN class deleted
- THEN 3 meetings cascade-deleted

### Scenario: N meetings per class allowed

- GIVEN class "Math"
- WHEN 2 meetings created
- THEN both persist

### Scenario: ViewMeeting shows details

- GIVEN meeting exists
- WHEN ViewMeeting loads
- THEN title, classroom, datetime, duration, agenda displayed; Join per window

### Scenario: dashboard ordered by scheduled_at

- GIVEN Aug 2 "Math" and Aug 1 "Physics" meetings
- WHEN /dashboard loads
- THEN "Physics" before "Math"

### Scenario: dashboard limits to 5

- GIVEN 7 upcoming meetings
- WHEN /dashboard loads
- THEN only 5 shown

### Scenario: dashboard empty state

- GIVEN student with zero upcoming meetings
- WHEN /dashboard loads
- THEN "No hay clases en vivo…"

### Scenario: dashboard "Live now!" indicator

- GIVEN meeting within ±15 min
- WHEN /dashboard loads
- THEN "Live now!" indicator shown

### Scenario: dashboard Join button for live

- GIVEN live-window meeting with url
- WHEN /dashboard loads
- THEN Join button, opens url in new tab

### Scenario: dashboard excludes past meetings

- GIVEN meeting scheduled_at < now() in subscribed class
- WHEN /dashboard loads
- THEN not shown in live section

### Scenario: dashboard auth gate

- GIVEN TEACHER at /dashboard
- THEN 403

### Scenario: dashboard subscription isolation

- GIVEN student NOT subscribed to class with meeting
- WHEN /dashboard loads
- THEN meeting not visible

### Scenario: UTC time zone storage

- GIVEN meeting created with scheduled_at
- WHEN stored and retrieved
- THEN stored UTC; Carbon display uses app timezone

### Scenario: ClassResource badge shows count

- GIVEN class "Math" with 3 meetings
- WHEN ClassResource table renders
- THEN "3 meetings" badge

## MODIFIED Requirements — teacher-class-management

### Requirement: Teacher-Scoped Class CRUD

The list table MUST display a meetings-count badge via `withCount('meetings')`.
(Previously: list without meetings-count badge.)

#### Scenario: Meetings-count badge

- GIVEN "Math" has 3 meetings, "Physics" has 0
- WHEN class list renders
- THEN "3 meetings" on Math, "0 meetings" on Physics

## MODIFIED Requirements — student-class-subscription

### Requirement: Dashboard

MUST display "Próximas clases en vivo": next 5 upcoming meetings (subscribed→meetings→upcoming→limit 5, scheduled_at ASC). "Live now!" indicator and Join button (url set AND within ±15 min). Empty: "No hay clases en vivo…". Excludes past meetings. Auth + role:STUDENT. Subscription isolation. UTC storage with Carbon display.
(Previously: dashboard without live-class section.)

#### Scenario: Dashboard live section lists upcoming

- GIVEN STUDENT subscribed to "Math" with upcoming "Week 1" meeting
- WHEN /dashboard loads
- THEN "Week 1" listed with classroom and formatted time

#### Scenario: Dashboard live section empty state

- GIVEN STUDENT with zero upcoming meetings
- WHEN /dashboard loads
- THEN "No hay clases en vivo…" shown

#### Scenario: Dashboard live section excludes past

- GIVEN meeting scheduled_at < now() in subscribed class
- WHEN /dashboard loads
- THEN past meeting not shown

#### Scenario: Dashboard live section "Live now!" + Join

- GIVEN live-window meeting with url set in subscribed class
- WHEN /dashboard loads
- THEN "Live now!" indicator and Join button rendered
