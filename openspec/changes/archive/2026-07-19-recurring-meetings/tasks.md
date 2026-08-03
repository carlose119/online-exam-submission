# Tasks: Recurring Meetings

## Phase 1: Data Layer

- [x] 1.1 Create migration `{ts}_add_recurrence_columns_to_meetings_table.php` adding `recurrence_rule` JSON (nullable) + `parent_id` FK (nullable, cascade) to `meetings` via `Schema::table()`

## Phase 2: Meeting Model

- [x] 2.1 Add `parent()` belongsTo self and `children()` hasMany self relations to `app/Models/Meeting.php`
- [x] 2.2 Add `isRecurring()`, `recurrenceRule()` accessor, `setRecurrenceRule()` mutator to Meeting model
- [x] 2.3 Add `generateInstances(int $count)` method creating N-1 children with computed `scheduled_at`
- [x] 2.4 Update `#[Fillable]` with `recurrence_rule` and `parent_id`

## Phase 3: Filament

- [x] 3.1 Add "Make this recurring" section to `MeetingResource` form: Toggle + conditional Section with frequency Select, interval TextInput, count TextInput
- [x] 3.2 Modify `CreateMeeting` to build `recurrence_rule` JSON in `mutateFormDataBeforeCreate` and call `generateInstances` in `afterCreate`
- [x] 3.3 Modify `EditMeeting` to propagate shared-field edits to future children via `afterSave`

## Phase 4: Testing

- [x] 4.1 Create `tests/Feature/RecurringMeetingTest.php` with 7 Pest tests:
  - Migration columns exist
  - Model relations and methods
  - One-off meeting creation (no recurrence)
  - Recurring series creation via generateInstances
  - Edit-all propagation (future updated, past untouched)
  - Delete cascade
  - recurrence_rule JSON round-trip

## Phase 5: Documentation

- [x] 5.1 Add "Recurring meetings" section to README after "Student profile"
- [x] 5.2 Update Live Class Materialization deferred items to reflect implemented recurrence
