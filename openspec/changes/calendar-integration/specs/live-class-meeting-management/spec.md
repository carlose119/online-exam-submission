# Delta for live-class-meeting-management

This delta adds five requirements (R16–R20) for the per-student `webcal://` calendar feed. The existing 17 requirements (R1–R15 + 2 MODIFIED from prior changes) are preserved.

## ADDED Requirements

### Requirement: Calendar Feed Token Management

The `users` table MUST gain a `feed_token` column (VARCHAR 64, UNIQUE, NULLABLE, INDEXED) via a new migration. `User::generateFeedToken()` SHALL produce `Str::random(64)`, persist to `feed_token`, and return the token. `User::regenerateFeedToken()` SHALL overwrite `feed_token` with a new 64-char token; the old token MUST immediately become invalid (404 on the feed endpoint). Token SHALL be generated lazily on first dashboard visit when NULL.

#### Scenario: feed_token column exists after migration

- GIVEN the migration is run
- WHEN inspecting the `users` table schema
- THEN `feed_token` exists as VARCHAR(64), unique, nullable, indexed

#### Scenario: generateFeedToken produces and persists token

- GIVEN a User with `feed_token` = NULL
- WHEN `generateFeedToken()` is called
- THEN returns a 64-character string AND the database row has `feed_token` set to that value

#### Scenario: regenerateFeedToken invalidates old token

- GIVEN a User with `feed_token` = "A"
- WHEN `regenerateFeedToken()` is called
- THEN the stored `feed_token` differs from "A" AND `GET /calendar/A.ics` returns 404

### Requirement: Calendar Feed Endpoint

`GET /calendar/{token}.ics` named `calendar.feed` MUST have NO auth middleware and NO route middleware — the token is the sole credential. `CalendarFeedController::feed(token)` SHALL eager-load the user's subscribed classes' meetings (parent + recurring children, past + future). Unknown token MUST return 404. Valid token MUST return 200 with: `Content-Type: text/calendar; charset=utf-8`, `Content-Disposition: inline; filename="calendar.ics"`, `Cache-Control: no-store`. Feed MUST include only meetings from subscribed classes; MUST exclude unsubscribed classes.

#### Scenario: 404 for unknown token

- GIVEN no user has token "missing"
- WHEN `GET /calendar/missing.ics` is requested
- THEN response status is 404

#### Scenario: 200 with valid iCal for known token

- GIVEN a user with token "ok" subscribed to a class with 2 meetings
- WHEN `GET /calendar/ok.ics` is requested
- THEN status is 200 AND `Content-Type` includes `text/calendar` AND body contains `VCALENDAR` with 2 `VEVENT` blocks

#### Scenario: feed includes past and future meetings

- GIVEN a subscribed class has 1 past meeting and 1 future meeting
- WHEN the feed is requested
- THEN `VEVENT` blocks exist for BOTH meetings

#### Scenario: feed excludes unsubscribed class meetings

- GIVEN user subscribed to Class A (meeting M1), NOT subscribed to Class B (meeting M2)
- WHEN the feed is requested
- THEN feed includes M1 but excludes M2

#### Scenario: feed includes recurring meeting instances

- GIVEN a subscribed class with a recurring series (parent + 3 children)
- WHEN the feed is requested
- THEN 4 `VEVENT` blocks appear (one per instance, no `RRULE`)

#### Scenario: correct response headers

- GIVEN a valid feed token
- WHEN the feed is requested
- THEN `Content-Type` is `text/calendar; charset=utf-8` AND `Content-Disposition` is `inline; filename="calendar.ics"` AND `Cache-Control: no-store`

#### Scenario: no auth required for the feed endpoint

- GIVEN a valid feed token
- WHEN the feed URL is requested without any auth session or token header
- THEN response status is 200 (the URL token is the only credential)

### Requirement: Multi-VEVENT iCal Builder

`IcalBuilder::buildMany(iterable $meetings): string` MUST produce a single `VCALENDAR` with one `VEVENT` per meeting. MUST reuse the per-VEVENT format from `build(Meeting $meeting)` — no code duplication.

#### Scenario: buildMany outputs multi-VEVENT iCalendar

- GIVEN a collection of 3 meetings
- WHEN `buildMany()` is called
- THEN output is a `VCALENDAR` containing 3 `VEVENT` blocks whose content matches per-meeting `build()` output

### Requirement: Dashboard Calendar Subscription Section

The dashboard MUST render a "Calendar subscription" section with: a read-only input displaying `route('calendar.feed', [auth()->user()->feed_token])`, a Copy button, and a Regenerate button with a confirmation dialog warning the old URL stops working. The token SHALL be lazily generated on first render when NULL.

#### Scenario: section renders with feed URL and Copy button

- GIVEN an authenticated student with a generated `feed_token`
- WHEN the dashboard renders
- THEN a read-only input shows the `calendar.feed` URL with the token AND a Copy button is visible

#### Scenario: Regenerate button triggers confirmation dialog

- GIVEN the dashboard "Calendar subscription" section
- WHEN the Regenerate button is clicked
- THEN a confirmation dialog appears before token regeneration executes

#### Scenario: after regeneration new URL works, old URL fails

- GIVEN a user with `feed_token` = "old"
- WHEN regeneration sets `feed_token` to "new"
- THEN `GET /calendar/old.ics` returns 404 AND `GET /calendar/new.ics` returns 200

### Requirement: Calendar Feed Test Coverage

`tests/Feature/CalendarFeedTest.php` MUST contain at least 4 scenarios: 404 on unknown token, 200 with valid multi-VEVENT `.ics`, feed includes only subscribed-class meetings, feed excludes unsubscribed, and regeneration invalidates old token. MUST pass alongside the existing 226-test suite. The change SHALL introduce 0 new composer dependencies and 0 schema changes beyond the `feed_token` ALTER on `users`.

#### Scenario: CalendarFeedTest.php covers all feed behaviours

- GIVEN the test file exists
- WHEN `php artisan test tests/Feature/CalendarFeedTest.php` runs
- THEN at least 4 tests pass covering unknown-token 404, valid-token 200 with headers, content filtering, and token rotation invalidation
