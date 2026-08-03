# Design: Recurring Meetings

## Technical Approach

Extend `live-class-meeting-management` (archived change `2026-07-20-live-class-materialization`, 10 requirements R1–R8 + 2 modified deltas on teacher-class-management and student-class-subscription) with recurrence support: 1 new migration adds `recurrence_rule` JSON + `parent_id` self-referencing FK to `meetings`; `Meeting` model gains self-referencing relations and `generateInstances()` that eagerly materializes N-1 child rows at creation; `MeetingResource` form gets a conditional "Make this recurring" section; `EditMeeting` propagates shared-field edits to future children only; delete cascades via FK. Student dashboard, existing scopes, and the Join window require zero changes — children are plain `meetings` rows the existing queries already surface. Zero new Composer dependencies.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|----------|--------|-------------|-----------|
| Recurrence storage | JSON column `recurrence_rule` on existing `meetings` + self-referencing `parent_id` FK | Separate `recurrence_rules` table | Single-table simpler; no JOIN needed for dashboard queries; children are discoverable via `parent_id` index. |
| Materialization | Eager (N-1 children created at submit time) | Lazy (compute on read) | Student dashboard query (`upcoming`, `scheduled_at ASC`, limit 5) works unchanged; no virtual-column complexity; materialized rows are indexed. Count capped in form (default 12, max 52). |
| Edit/delete scope | Edit-all + delete-all (entire series) | Per-instance exceptions ("edit this only" / "delete this only") | Scope matches basic recurrence use case; per-instance exceptions deferred to future change. FK cascade handles delete-all with zero application code. |
| `recurrence_rule` cast | Custom accessor `recurrenceRule(): ?array` + mutator `setRecurrenceRule(?array)`, no Eloquent `'array'` cast | Eloquent `'array'` cast | Explicit control over encode/decode; mutation writes to `$this->attributes` directly ensuring clean store semantics without double-encoding risk. |

## Data Flow

```
Teacher CREATE recurring meeting
  └─ mutateFormDataBeforeCreate(): builds recurrence_rule JSON from form fields
     └─ parent Meeting created with recurrence_rule set
        └─ afterCreate(): calls generateInstances(count)
           └─ N-1 children created: same class_id/title/duration/url/agenda
              + recurrence_rule=null + parent_id=parent.id + computed scheduled_at

Teacher EDIT parent meeting
  └─ afterSave(): isRecurring()? → children()->where('scheduled_at','>=',now())
     → update([title, agenda, duration_minutes, meeting_url])
     → past children (scheduled_at < now()) NOT modified

Teacher DELETE parent meeting
  └─ FK onDelete('cascade') removes all children automatically

Student DASHBOARD
  └─ existing query: subscribed classes → upcoming() → scheduled_at ASC → limit 5
     → surfaces children as ordinary meetings (no code change)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/{ts}_add_recurrence_columns_to_meetings_table.php` | Create | `Schema::table('meetings', …)` adds `recurrence_rule` JSON (nullable) + `parent_id` unsignedBigInteger FK→meetings.id nullable with `cascadeOnDelete()`. |
| `app/Models/Meeting.php` | Modify | Add `parent()`, `children()`, `isRecurring()`, `recurrenceRule()`, `setRecurrenceRule()`, `generateInstances(int)`. Extend `#[Fillable]` with `recurrence_rule`, `parent_id`. |
| `app/Filament/Resources/MeetingResource.php` | Modify | Form: add `Section` with `Toggle('is_recurring')` + conditional `Select('frequency')`, `TextInput('interval')`, `TextInput('count')`. Table: add `BadgeColumn` on parent rows. |
| `app/Filament/Resources/MeetingResource/Pages/CreateMeeting.php` | Modify | Override `mutateFormDataBeforeCreate()` to encode `recurrence_rule` JSON; override `afterCreate()` to call `generateInstances()`. |
| `app/Filament/Resources/MeetingResource/Pages/EditMeeting.php` | Modify | Override `afterSave()` to propagate shared-field edits to future children. |
| `tests/Feature/RecurringMeetingTest.php` | Create | 7 Pest tests: migration columns, model methods, one-off vs recurring creation, generateInstances, edit-all propagation, delete-all cascade, JSON round-trip. |
| `README.md` | Modify | Insert "Recurring meetings" section after "Student profile" covering creation workflow, edit-all/delete-all flows, JSON structure, deferred items. |

## Interfaces / Contracts

**`recurrence_rule` JSON structure:**
```json
{
  "frequency": "weekly",
  "interval": 1,
  "count": 12,
  "until": null,
  "days_of_week": null
}
```
- `frequency`: `weekly` | `biweekly` | `monthly` (default: `weekly`)
- `interval`: positive int (1 = every, 2 = every other; default: 1)
- `count`: total instances including parent (default: 12, max: 52)
- `until`: optional timestamp alternative to count
- `days_of_week`: optional `[0..6]` array for multi-day weekly

**`generateInstances(int $count)` PHP interface:**
- `$count` = total instances (parent + children)
- Children = `$count - 1`
- `scheduled_at` computation: `weekly/biweekly` → `$parent->scheduled_at->copy()->addWeeks($interval * $i)`, `monthly` → `addMonthsNoOverflow($interval * $i)`
- Returns `Collection` of created child `Meeting` instances

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Migration columns exist | `RefreshDatabase`, run migration, assert `Schema::hasColumns()` |
| Feature | Model relations and methods | Create parent + children, assert `parent()`, `children()`, `isRecurring()`, `recurrenceRule()` round-trip |
| Feature | One-off creation (toggle off) | Submit form without toggle → `recurrence_rule=null`, no children |
| Feature | Recurring creation (toggle on) | Submit with frequency/count → parent with rule + N-1 children |
| Feature | Edit-all propagation | Create parent + 3 children (1 past, 2 future), edit title → future updated, past untouched |
| Feature | Delete-all cascade | Delete parent → assert children removed via FK cascade |
| Feature | JSON round-trip | Set `recurrence_rule` array → persist → retrieve → assert decoded matches |

Tests are written AFTER implementation per this project's `tdd: false` convention. `RefreshDatabase` trait (SQLite `:memory:`) is used — no MariaDB needed. Frozen time via `Carbon::setTestNow()` for the edit-all propagation test.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No data migration required. Existing one-off meetings have `recurrence_rule=null` and `parent_id=null` — the new columns are nullable, so existing rows are unaffected. Rollback: `php artisan migrate:rollback` drops the two columns; `git revert` the model/resource/test changes.

## Open Questions

- [ ] The canonical `openspec/specs/live-class-meeting-management/spec.md` does not exist yet (the previous archive step missed creating it from `openspec/changes/archive/2026-07-20-live-class-materialization/specs/live-class-meeting-management/spec.md`). The archive step for `recurring-meetings` must create it as part of merging this change's delta spec.
