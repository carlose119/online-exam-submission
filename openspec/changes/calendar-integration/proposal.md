# Proposal: Calendar Subscription (webcal://) Feed

## Intent

`ical-export` (archived) lets a student download a single meeting as `.ics`, but does not auto-sync: every new or updated meeting requires a manual re-download. Students live in Google Calendar / Outlook and expect the LMS to push their schedule there the way an institutional calendar does. `calendar-integration` adds a per-student `webcal://` feed — one URL per student, authenticated by a unique opaque token — that calendar clients fetch periodically to keep every meeting (past + future) from their subscribed classes in sync. This is a delta on the canonical `live-class-meeting-management` capability: it reuses the `meetings` table, the `Meeting` model, the existing `IcalBuilder::build()` per-meeting format, and the dashboard's `subscribedClasses()` relationship. No new composer dependencies; one small ALTER on `users`.

## Scope

### In Scope
- 1 new migration: `ALTER TABLE users ADD COLUMN feed_token VARCHAR(64) UNIQUE NULLABLE INDEX` (nullable so existing rows survive; back-filled lazily on first regeneration/dashboard visit).
- 2 new methods on `app/Models/User.php`: `generateFeedToken()` and `regenerateFeedToken()` — both produce `Str::random(64)`, persist it, return the token; idempotent and safe to call repeatedly.
- 1 new method on `app/Services/IcalBuilder.php`: `buildMany(iterable $meetings): string` — emits a single `VCALENDAR` with one `VEVENT` per Meeting, reusing the per-meeting block from `build(Meeting $meeting)` (no duplication).
- 1 new controller `app/Http/Controllers/CalendarFeedController.php` with a single action `feed(string $token)`:
  - `User::where('feed_token', $token)->first()` → 404 if not found.
  - Eager-load `$user->subscribedClasses()->with('meetings')`, flatten to a collection of `Meeting` models (parent + children included — recurring series already materialised as rows).
  - Response: `Content-Type: text/calendar; charset=utf-8`, `Content-Disposition: inline; filename="calendar.ics"` (`inline`, not `attachment`, so the calendar client renders rather than downloads), `Cache-Control: no-store, max-age=0`.
- 1 new route `routes/web.php`: `GET /calendar/{token}.ics` named `calendar.feed` — **no auth, no middleware** (the token is the only credential; calendar clients cannot send auth headers on subscription).
- 1 new Livewire action on the existing `app/Livewire/Dashboard.php`: `regenerateFeedToken()` — generates a new token, persists it; old URL immediately 404s. Renders a confirmation dialog warning that the old URL stops working.
- 1 new dashboard section in `resources/views/livewire/dashboard.blade.php`: read-only text input with the current feed URL (`route('calendar.feed', ['token' => auth()->user()->feed_token])`), a "Copiar" (Copy) button, and a "Regenerar" (Regenerate) button with confirmation dialog.
- 1 new Pest file `tests/Feature/CalendarFeedTest.php` (4–5 scenarios): 404 on unknown token; 200 with valid multi-VEVENT `.ics` on known token; feed includes only meetings from the user's subscribed classes; feed excludes meetings from unsubscribed classes; after regeneration the old token returns 404 and the new token returns 200.
- 1 README update: new "Calendar subscription (webcal://)" section after "iCalendar export" — how to subscribe (copy URL, add to Google Calendar / Outlook via "From URL"), feed content (all meetings from subscribed classes, past + future), token regeneration flow + compromise guidance, deferred items.
- 0 new composer dependencies.

### Out of Scope
- Per-class `webcal://` feed (a teacher/admin URL for an entire class) — deferred.
- Past-meetings-only feed variant — deferred.
- RRULE in the feed: recurring series export as a single `VEVENT` with `RRULE` is NOT delivered; each materialised instance is its own `VEVENT` (matches `recurring-meetings` data model).
- Attendees (`ATTENDEE` property), VALARM reminders, attachments in the feed.
- Email notifications / reminders for the calendar feed (no mailer configured in stack).
- Per-meeting reminders (depends on email; deferred).
- Token expiry / rotation policy enforced by the system — only student-initiated regeneration.

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `live-class-meeting-management`: the student side of the capability gains a per-student `webcal://` calendar feed endpoint (`GET /calendar/{token}.ics`), a `feed_token` column on `users`, `User::generateFeedToken()` / `regenerateFeedToken()` methods, an `IcalBuilder::buildMany()` multi-VEVENT helper, and a dashboard "Calendar subscription" section with Copy + Regenerate. The `meetings` table, `Meeting` model, scopes, Filament `MeetingResource`, recurring-meetings behaviour, the existing dashboard "Próximas clases en vivo" listing/Join flow, and the existing single-meeting `.ics` download (`ical-export`) are unchanged.

## Approach

Token-in-URL is the standard `webcal://` pattern: calendar clients (Google Calendar "From URL", Outlook "Subscribe from web", Apple Calendar "New Subscription") issue periodic HTTPS GETs and cannot attach auth headers, so the token is the sole credential. `feed_token` is a 64-char opaque random string, unique, indexed — lookup is O(log n) and collisions are practically impossible. The controller is a thin read: load user → eager-load subscribed classes' meetings → `IcalBuilder::buildMany()` → respond with `text/calendar` + `inline` disposition + `no-store`. `buildMany()` shares the per-`VEVENT` block with `build()` so the iCal wire format stays single-sourced. Regeneration is a server-side `Str::random(64)` overwrite; the old row's `feed_token` value simply no longer exists, so the old URL 404s on the next fetch. Dashboard shows the URL read-only so the student can copy it; the "Regenerar" action runs behind a confirmation dialog because it invalidates the existing subscription in every calendar client the student has configured.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/{ts}_add_feed_token_to_users_table.php` | New | `ALTER TABLE users ADD feed_token VARCHAR(64) UNIQUE NULLABLE INDEX`. |
| `app/Models/User.php` | Modified | Adds `generateFeedToken()` + `regenerateFeedToken()` (both use `Str::random(64)`, persist, return token). |
| `app/Services/IcalBuilder.php` | Modified | Adds `buildMany(iterable $meetings): string` reusing the per-`VEVENT` block from `build()`. |
| `app/Http/Controllers/CalendarFeedController.php` | New | `feed(string $token)` — token lookup (404 if missing), eager-load subscribed classes' meetings, return `buildMany()` with `text/calendar` + `inline` + `no-store`. |
| `routes/web.php` | Modified | Adds `GET /calendar/{token}.ics` named `calendar.feed` — no auth, no middleware. |
| `app/Livewire/Dashboard.php` + Blade view | Modified | New "Calendar subscription" section: read-only URL input, "Copiar" button, "Regenerar" button with confirmation dialog. |
| `tests/Feature/CalendarFeedTest.php` | New | Pest: 404 unknown token, 200 valid token, subscribed-only content, excludes unsubscribed, token-rotation invalidation. |
| `README.md` | Modified | New "Calendar subscription (webcal://)" section after "iCalendar export". |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Token = the only auth: a leaked URL (shared, referrer, browser history) exposes the student's calendar of classes. | Med | Document compromise flow in README; one-click "Regenerar" immediately invalidates the old URL; 64-char opaque token resists guessing; never log the token in plain text. |
| Calendar-client caching: we send `Cache-Control: no-store` but clients (Google Calendar) poll on their own schedule (often 8–24h) regardless of headers. | High | Document the polling limitation in README so students know updates are not instant; `no-store` is correct for clients that do honour it. |
| Recurring meetings in the feed: all instances materialised as rows — feed emits one `VEVENT` per row, no `RRULE`. A long series produces a large `.ics`. | Med | Cap is implicit (number of meetings per subscribed class); document; future RRULE support deferred. |
| Per-class feed deferred: teachers/admins wanting a class-wide calendar URL must wait for a future change. | Low | Explicit non-goal; per-student feed covers the primary user need. |
| Email notifications deferred: no mailer configured, so compromise alerts or "your feed was regenerated" confirmations are not emailed. | Low | Regeneration success shown in the UI; email deferred to a future change once mailer is configured. |
| `feed_token` column nullable → a student who never visits the dashboard has `NULL`; the feed URL cannot be built until `generateFeedToken()` runs. | Low | Dashboard lazily generates the token on first render if null; first subscription works from that visit. |
| `buildMany()` shares the per-`VEVENT` block with `build()` — a bug in the shared block now affects both single-export and feed. | Low | Reuse is the point (single source of truth); existing `IcalExportTest` plus new `CalendarFeedTest` cover both paths. |

## Rollback Plan

1. Revert `routes/web.php` (remove `calendar.feed`) — feed URL 404s; calendar clients fail gracefully.
2. Revert the Dashboard Blade view "Calendar subscription" section (or leave it; the URL 404s once the route is gone).
3. Revert `app/Livewire/Dashboard.php` `regenerateFeedToken()` action.
4. Delete `app/Http/Controllers/CalendarFeedController.php` and `tests/Feature/CalendarFeedTest.php`.
5. Revert `app/Services/IcalBuilder.php` (remove `buildMany()`) — single-meeting `build()` is untouched and `ical-export` keeps working.
6. Revert `app/Models/User.php` (remove `generateFeedToken()` / `regenerateFeedToken()`).
7. Drop the `feed_token` column with a down() migration: `ALTER TABLE users DROP COLUMN feed_token`. No data loss beyond the token itself; user accounts, classes, meetings, and subscriptions are untouched.
8. Revert README "Calendar subscription (webcal://)" section.

## Dependencies

- Laravel 13.19.0, Filament v5.6.8, Livewire v4.3.3, MariaDB 10.11.9, Pest v4.7.5, Carbon — all already installed (0 new composer deps).
- Canonical spec `live-class-meeting-management` — this change is a delta on it; the `meetings` table, `Meeting` model, scopes, and student dashboard "Próximas clases en vivo" listing are reused unchanged.
- Archived `ical-export` — `app/Services/IcalBuilder.php` `build(Meeting $meeting): string` is extended (not replaced) with `buildMany()`.
- Archived `recurring-meetings` — recurring series are already materialised as individual `meetings` rows, so the feed emits one `VEVENT` per instance with no `RRULE` logic needed here.
- `app/Models/User.php` — `subscribedClasses()` relationship (from student-class-subscription) is reused to scope feed content.

## Success Criteria

- [ ] Authenticated student can read their `feed_token` from the dashboard "Calendar subscription" section (generated lazily if null).
- [ ] `GET /calendar/{token}.ics` with a valid token returns 200 + `Content-Type: text/calendar; charset=utf-8` + `Content-Disposition: inline; filename="calendar.ics"` + `Cache-Control: no-store`.
- [ ] Feed body is a valid `VCALENDAR` with one `VEVENT` per meeting from the user's subscribed classes (parent + recurring children included).
- [ ] Feed does NOT include meetings from classes the user is not subscribed to.
- [ ] Unknown or invalidated token returns 404.
- [ ] After clicking "Regenerar" (with confirmation), the new token's feed returns 200 and the old token's feed returns 404.
- [ ] Unauthenticated requests to `GET /calendar/{token}.ics` succeed (no auth middleware — token is the credential).
- [ ] `tests/Feature/CalendarFeedTest.php` passes alongside the existing suite (226 prior tests untouched).
- [ ] README "Calendar subscription (webcal://)" section documents subscribe flow, feed content, regeneration, and deferred items (per-class feed, RRULE, attendees, email notifications).
