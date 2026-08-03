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

---

# ADDED by recurring-meetings change (2026-07-19)

## ADDED Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| R9 | Recurrence Data Model | `meetings` gains nullable `recurrence_rule` JSON and nullable `parent_id` FK→`meetings.id` with `onDelete('cascade')`. `parent()` belongsTo self; `children()` hasMany self. Accessor decodes JSON; mutator encodes. |
| R10 | Meeting Recurrence Methods | `isRecurring()` SHALL return `$this->recurrence_rule !== null`. `generateInstances(int $count)` MUST create N-1 children with `recurrence_rule=null`, computing `scheduled_at` via rule: weekly=+7·i days, biweekly=+14·i, monthly=+1 month·i. Frequency: `weekly`\|`biweekly`\|`monthly`. Interval default: 1. Count default: 12. |
| R11 | Recurring Meeting Creation | `MeetingResource` form MUST include "Make this recurring" section: "Is recurring?" checkbox revealing frequency Select, interval TextInput, count TextInput. Unchecked submit → one-off (no rule, no children). Checked submit → parent with `recurrence_rule` + `generateInstances(count - 1)` children. |
| R12 | Edit-All Propagation | Editing a parent MUST propagate `title`, `agenda`, `duration_minutes`, `meeting_url` to children where `scheduled_at >= now()`. Children where `scheduled_at < now()` MUST NOT be modified. |
| R13 | Delete-All Cascade | Deleting a parent MUST cascade-delete all children via `parent_id` FK `onDelete('cascade')`. No custom code required. |
| R14 | Student Dashboard Compatibility | The existing "Próximas clases en vivo" dashboard SHALL display recurring instances unchanged — children are `meetings` rows the existing query already surfaces. |
| R15 | Deferred Scope | The system MUST NOT export recurring meeting series in iCal format. Email reminders for recurring meetings are deferred. "Edit this only" and "Delete this only" per-instance operations are deferred. |

### Scenario: Migration columns exist

- GIVEN the migration is run
- WHEN inspecting the `meetings` table schema
- THEN `recurrence_rule` column exists (JSON, nullable) AND `parent_id` FK exists (nullable, cascade to meetings.id)

### Scenario: recurrence_rule JSON round-trip

- GIVEN a meeting with `recurrence_rule` set to `{frequency: "weekly", interval: 1, count: 12}`
- WHEN stored and retrieved via `$meeting->recurrenceRule()`
- THEN decoded array matches input values

### Scenario: isRecurring detection

- GIVEN parent meeting with `recurrence_rule` set AND child meeting with `recurrence_rule=null`
- WHEN calling `isRecurring()` on each
- THEN parent returns `true`, child returns `false`

### Scenario: generateInstances creates children

- GIVEN parent with `recurrence_rule {frequency: "weekly", interval: 1, count: 4}`
- WHEN `generateInstances(4)` is called
- THEN 3 children created with `recurrence_rule=null`, `parent_id=parent.id`, scheduled +7/+14/+21 days

### Scenario: One-off meeting creation

- GIVEN teacher at `MeetingResource` create form with "Is recurring?" UNCHECKED
- WHEN form submitted with valid data
- THEN one meeting created with `recurrence_rule=null` AND `parent_id=null` AND zero children

### Scenario: Recurring meeting creation

- GIVEN teacher at `MeetingResource` create form with "Is recurring?" CHECKED, frequency "weekly", interval 1, count 4
- WHEN form submitted
- THEN parent with `recurrence_rule` + 3 children created

### Scenario: Edit all propagates to future children

- GIVEN parent with 3 children: one scheduled yesterday, two scheduled tomorrow
- WHEN teacher edits parent changing title to "Updated Topic"
- THEN yesterday's child retains old title AND both tomorrow's children show "Updated Topic"

### Scenario: Edit all preserves per-instance scheduled_at

- GIVEN parent with child scheduled 2026-08-10
- WHEN teacher edits parent changing only title
- THEN the child's `scheduled_at` remains 2026-08-10

### Scenario: Delete all cascades to children

- GIVEN parent with 3 children
- WHEN parent is deleted
- THEN parent and all 3 children removed from `meetings` table

### Scenario: Student dashboard shows recurring instances

- GIVEN student subscribed to a class with a recurring meeting (parent + 3 children)
- WHEN `/dashboard` loads
- THEN all 4 upcoming instances appear in "Próximas clases en vivo" ordered by `scheduled_at ASC`
