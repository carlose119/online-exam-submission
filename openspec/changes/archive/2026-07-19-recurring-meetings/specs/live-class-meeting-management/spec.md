# Delta for live-class-meeting-management

This delta ADDS recurring-meeting support to `live-class-meeting-management` (canonical spec: `openspec/specs/live-class-meeting-management/spec.md`, 10 requirements from change `2026-07-20-live-class-materialization`). Existing R1–R8 plus the two MODIFIED deltas on `teacher-class-management` and `student-class-subscription` are PRESERVED.

## ADDED Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| R9 | Recurrence Data Model | `meetings` gains nullable `recurrence_rule` JSON and nullable `parent_id` FK→`meetings.id` with `onDelete('cascade')`. `parent()` belongsTo self; `children()` hasMany self. Accessor decodes JSON; mutator encodes. |
| R10 | Meeting Recurrence Methods | `isRecurring()` SHALL return `$this->recurrence_rule !== null`. `generateInstances(int $count)` MUST create N-1 children with `recurrence_rule=null`, computing `scheduled_at` via rule: weekly=+7·i days, biweekly=+14·i, monthly=+1 month·i. Frequency: `weekly`|`biweekly`|`monthly`. Interval default: 1. Count default: 12. |
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
