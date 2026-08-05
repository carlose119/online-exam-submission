# Apply Progress: Calendar Integration

## PR 1 — Foundation Token Lifecycle

**Status**: complete for PR 1 only; PR 2 and PR 3 remain pending.

### Completed Tasks

- [x] 1.1 Add nullable, unique `VARCHAR(64)` `users.feed_token` migration with reversible `down()`.
- [x] 1.2 Add collision-safe persisted token generation and regeneration to `User`.
- [x] 1.3 Add the first focused token lifecycle case to `CalendarFeedTest`.

### Changed Files

- `database/migrations/2026_08_04_000000_add_feed_token_to_users_table.php` — nullable unique feed token column and rollback.
- `app/Models/User.php` — hidden, non-fillable tokens with bounded unique-collision retries.
- `tests/Feature/CalendarFeedTest.php` — schema, persistence, length, overwrite, hidden, and uniqueness coverage.
- `openspec/changes/calendar-integration/tasks.md` — marks tasks 1.1–1.3 only.
- `openspec/changes/calendar-integration/apply-progress.md` — records this PR 1 work-unit evidence and remaining slices.

### Work Unit Evidence

| Evidence | Result |
|---|---|
| Focused test | `php artisan test tests/Feature/CalendarFeedTest.php --filter="feed tokens"` — passed: 1 test, 11 assertions. |
| Formatter | `vendor/bin/pint app/Models/User.php database/migrations/2026_08_04_000000_add_feed_token_to_users_table.php tests/Feature/CalendarFeedTest.php` — passed and convergent. |
| Runtime harness | N/A — this slice has only migration/model persistence; the public feed runtime boundary is PR 2. |
| Rollback boundary | Revert the migration, `User` token methods/hidden attribute, and first token lifecycle test without affecting later feed or dashboard work. |

### Remaining Slices

- PR 2 → `main`: tasks 2.1–2.3, shared multi-event iCal serialization and public feed endpoint.
- PR 3 → `main`: tasks 3.1–3.4, dashboard UX, documentation, and regression verification.

**Delivery**: stacked-to-main; PR 1 targets `main` and has no prior slice.
