# Proposal: Recurring Meetings

## Intent

Teachers currently schedule each live class meeting one by one. For courses that meet on a fixed cadence (weekly tutoring, biweekly seminars, monthly reviews), this is tedious and error-prone: a 12-week course means 12 identical forms. `recurring-meetings` lets a teacher define a basic recurrence (weekly/biweekly/monthly, N instances) once and have the system eagerly materialize every instance up front, so the existing `live-class-meeting-management` dashboard and Join flow work unchanged. This extends — does not replace — the canonical `live-class-meeting-management` capability (the `meetings` table and `Meeting` model come from it).

## Scope

### In Scope
- 1 migration MODIFYING `meetings`: add nullable `recurrence_rule` JSON column and nullable `parent_id` FK → `meetings.id` `onDelete('cascade')`.
- `Meeting` model: `parent()` belongsTo (self), `children()` hasMany, `isRecurring()`, `recurrenceRule()` accessor (decoded array), `setRecurrenceRule(array)` mutator, `generateInstances(int $count)` creating N-1 children from the parent's `recurrence_rule`.
- `MeetingResource` form: optional "Make this recurring" section (checkbox + frequency/interval/count + optional `days_of_week`) revealed when checked; on submit, parent is created with rule, then `generateInstances(count - 1)` runs.
- "Edit all" flow on parent edit (Filament `afterSave`): propagate `title`, `agenda`, `duration_minutes`, `meeting_url` to children whose `scheduled_at >= now()`. Past children are NOT modified.
- "Delete all" flow: deleting a parent cascades to all children via the FK (no custom code).
- Pest suite `tests/Feature/RecurringMeetingTest.php`: migration columns, model methods, `generateInstances`, recurring vs one-off form submit, edit-all (future updated, past untouched), delete-all cascade, `recurrence_rule` JSON shape.
- README "Recurring meetings" section after "Student profile": creation, edit-all/delete-all, JSON structure, deferred items.

### Out of Scope
- RRULE (RFC 5545) custom patterns (e.g. "Tue + Thu at 18:00").
- "Edit this only" / "Delete this only" — only edit-all / delete-all this slice.
- Recurring `study_materials`.
- iCal/calendar invitations export of the series.
- Email reminders for recurring meetings.
- Modification of past child instances when editing the parent.

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `live-class-meeting-management`: the `meetings` table gains `recurrence_rule` JSON + `parent_id` self-FK; the `Meeting` model gains recurrence relations/methods/accessor; `MeetingResource` gains an optional recurring-section + edit-all/delete-all behaviours. Existing one-off meeting behaviour, scopes, dashboard, and Join window are unchanged.

## Approach

Eager materialization: at creation, the parent row stores `recurrence_rule` and N-1 child rows are written immediately by `generateInstances()`, each with `scheduled_at` computed from the rule (weekly => +7·i days, biweekly => +14·i, monthly => +1 month·i; `days_of_week`, if set, fans a weekly parent into the listed weekdays). Children carry `recurrence_rule = null` and `parent_id = parent.id`, so the existing student dashboard query (`upcoming`, `scheduled_at ASC`, limit 5) already surfaces them without change.

Edit-all is a custom `afterStateSaved`/`afterSave` hook on the Filament Edit page: for the edited parent, `children()->where('scheduled_at', '>=', now())->update([...shared fields...])` — `scheduled_at` and recurrence metadata stay per-instance; only `title`/`agenda`/`duration_minutes`/`meeting_url` propagate. Delete-all relies on the FK cascade; no application code. UTC storage and Carbon display remain per existing spec.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/{ts}_add_recurrence_to_meetings_table.php` | New | Adds `recurrence_rule` JSON (nullable) + `parent_id` FK (nullable, cascade). |
| `app/Models/Meeting.php` | Modified | Adds `parent()`, `children()`, `isRecurring()`, `recurrenceRule()`, `setRecurrenceRule()`, `generateInstances()`. |
| `app/Filament/Resources/MeetingResource.php` | Modified | Optional recurring section in form; edit-all hook on Edit page; delete-all via cascade. |
| `tests/Feature/RecurringMeetingTest.php` | New | Pest coverage for migration, model, form, edit-all, delete-all, JSON shape. |
| `README.md` | Modified | New "Recurring meetings" section. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Basic scope only — no RRULE; users wanting "Tue + Thu" or "third Friday" must wait for a later change. | High | Document explicitly in README + proposal as deferred; JSON `days_of_week` covers simple multi-day weekly now. |
| Edit-all / Delete-all only — no "edit this only" exception. A single exception meeting must currently be hand-edited per instance or rebuilt. | Med | Document; a future "edit this only" change can split a child off the series (detach `parent_id`). |
| Past child instances are immutable on edit-all — if a teacher wants to fix a typo on an already-held session, they cannot via edit-all. | Med | By design (content is frozen post-attendance); teacher can edit the past child row directly in Filament if needed. |
| Calendar invitations (iCal) and email reminders deferred — students rely on the dashboard only. | Low | Out of scope; existing dashboard already lists every instance. |
| Eager materialization cost: a 52-week monthly series = 12 rows; worst-case weekly with `days_of_week`×5 = up to ~260 rows per parent. | Low | Cap `count` in the form (TBD: reasonable default 12, upper bound e.g. 104); write in one transaction. |
| Self-referential FK + cascade could mask orphan bugs if a child is edited to drop `parent_id`. | Low | Migration sets `onDelete('cascade')`; tests assert children vanish with parent. |

## Rollback Plan

1. Revert the new migration via `php artisan migrate:rollback` (drops `recurrence_rule` and `parent_id`).
2. Revert the `Meeting.php`, `MeetingResource.php`, README, and test file changes via `git revert` of the change's commits.
3. Existing one-off meetings are unaffected — they were never assigned `recurrence_rule`/`parent_id`; the dropped columns simply disappear.
4. No data migration needed: any recurring series created during the trial are deleted by rolling back the migration (rows persist but lose recurrence metadata — acceptable for a pre-release rollback).

## Dependencies

- Laravel 13.19.0, Filament v5.6.8, Livewire v4.3.3, MariaDB 10.11.9, Pest v4.7.5 — all already installed (0 new composer deps).
- Canonical spec `live-class-meeting-management` (archived change `2026-07-20-live-class-materialization`) provides the `meetings` table, `Meeting` model, `MeetingResource`, and student dashboard section this change extends.

## Success Criteria

- [ ] Teacher can create one recurring meeting (weekly/biweekly/monthly, count up to a sensible cap) and N rows appear in `meetings` after submit.
- [ ] Teacher can create a one-off meeting with the checkbox unchecked — behaviour identical to today.
- [ ] Editing a parent propagates shared fields to future children only; past children are unchanged.
- [ ] Deleting a parent removes the parent and all children via cascade.
- [ ] Student dashboard "Próximas clases en vivo" shows recurring instances unchanged (no query edit).
- [ ] `tests/Feature/RecurringMeetingTest.php` passes alongside the existing 211-test suite.
- [ ] README documents the recurrence_rule JSON, edit-all/delete-all flows, and deferred items.