# Apply Progress: Calendar Integration

## PR 1 — Foundation Token Lifecycle

**Status**: PR 1 and PR 2 are complete; PR 3 remains pending.

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

## PR 2 — Public iCalendar Feed

**Status**: complete for PR 2 only; PR 3 remains pending.

### Completed Tasks

- [x] 2.1 Refactor `IcalBuilder` with shared VCALENDAR/VEVENT serialization and add `buildMany()`.
- [x] 2.2 Add the public `calendar.feed` endpoint with token lookup, subscribed-class scoping, and exact no-store response headers.
- [x] 2.3 Extend `CalendarFeedTest` to four total scenarios through PR 2.

### Changed Files

- `app/Services/IcalBuilder.php` — shares VEVENT serialization between `build()` and `buildMany()` under one VCALENDAR envelope.
- `app/Http/Controllers/CalendarFeedController.php` — resolves feed tokens, eagerly loads subscribed meetings with teacher data, and returns an inline no-store calendar response.
- `routes/web.php` — adds public `GET /calendar/{token}.ics` named `calendar.feed`.
- `tests/Feature/CalendarFeedTest.php` — adds unknown-token, unauthenticated past/future multi-event, and subscribed recurrence-isolation coverage.
- `openspec/changes/calendar-integration/tasks.md` — marks only tasks 2.1–2.3 complete.
- `openspec/changes/calendar-integration/apply-progress.md` — merges PR 2 evidence while retaining PR 1 history.

### Work Unit Evidence

| Evidence | Result |
|---|---|
| Focused test | `php artisan test tests/Feature/CalendarFeedTest.php` — passed: 4 tests, 32 assertions. |
| Regression test | `php artisan test tests/Feature/IcalExportTest.php` — passed: 8 tests, 31 assertions. |
| Formatter | `vendor/bin/pint app/Services/IcalBuilder.php app/Http/Controllers/CalendarFeedController.php routes/web.php tests/Feature/CalendarFeedTest.php` and `vendor/bin/pint --test ...` — passed and convergent. |
| Runtime harness | Unauthenticated Laravel feature request to `GET /calendar/{token}.ics` in `CalendarFeedTest` — passed with a two-event past/future feed and exact headers. |
| Route audit | `php artisan route:list --name=calendar.feed` — passed: one public `GET|HEAD calendar/{token}.ics` route to `CalendarFeedController@feed`. |
| Diff hygiene | `git diff --check --` for PR 2 paths — passed with no whitespace errors. |
| Rollback boundary | Revert the builder, feed controller, public route, and the three PR 2 feed tests; token lifecycle from PR 1 and all dashboard/documentation work remain intact. |

### Remaining Slices

- PR 3 → `main`: tasks 3.1–3.4, dashboard UX, documentation, and regression verification.

**Delivery**: stacked-to-main; PR 2 is the public-feed slice after local PR 1 commits `be29403` and `7e88439`. Receipt-driven delivery is disabled/unmanaged for this clone; no native review receipt was created.
