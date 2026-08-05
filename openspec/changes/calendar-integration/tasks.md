# Tasks: Calendar Subscription Feed

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated authored changed lines | 520–620 |
| Estimated authored file count | 9 |
| Generated lines estimate | 0 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → main: foundation; PR 2 → main: feed; PR 3 → main: dashboard/docs/regression |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |
| Size exception | Not approved |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

Apply only the next autonomous slice; do not implement all three PRs in one apply run. Each PR merges to `main` in order after its focused verification passes.

### Suggested Work Units

| Unit | Goal | PR boundary and dependency | Focused test command | Runtime harness | Rollback boundary |
|------|------|---------------------------|----------------------|-----------------|-------------------|
| 1 | Migration and token lifecycle | PR 1 → `main`; no prior slice | `php artisan test tests/Feature/CalendarFeedTest.php --filter="token"` | N/A: schema/model boundary | migration + `User.php` + token test |
| 2 | Shared iCal serialization and public feed | PR 2 → `main`, after PR 1 merges | `php artisan test tests/Feature/CalendarFeedTest.php --filter="feed|ical"` | `php artisan serve`; unauthenticated `GET /calendar/{token}.ics` | builder, controller, route, feed tests |
| 3 | Dashboard subscription UX and documentation | PR 3 → `main`, after PR 2 merges | `php artisan test tests/Feature/CalendarFeedTest.php --filter="dashboard" && php artisan test tests/Feature/IcalExportTest.php` | Authenticated student visits `/dashboard`, copies and regenerates URL | dashboard files, README, UX test |

## Phase 1: Foundation and Token Lifecycle

- [ ] 1.1 Create `database/migrations/{ts}_add_feed_token_to_users_table.php`; add nullable indexed unique `VARCHAR(64)` and reversible `down()`; evidence: schema test confirms type, nullable, unique/indexed.
- [ ] 1.2 Modify `app/Models/User.php`; add non-fillable `generateFeedToken()`/`regenerateFeedToken()` using collision-safe `Str::random(64)` issuance; evidence: persistence, length, overwrite, and old-token invalidation assertions.
- [ ] 1.3 Create the first case in `tests/Feature/CalendarFeedTest.php` for migration/token persistence; tests follow implementation (strict TDD is disabled).

## Phase 2: Feed and Serialization

- [ ] 2.1 Modify `app/Services/IcalBuilder.php`; extract shared VEVENT/envelope helpers and add `buildMany(iterable $meetings)` with CRLF and no duplicated serialization.
- [ ] 2.2 Create `app/Http/Controllers/CalendarFeedController.php` and modify `routes/web.php`; add unauthenticated `calendar.feed`, token 404, subscribed-class eager loading with teacher, all past/future rows, and `Content-Type`, inline `Content-Disposition`, `Cache-Control: no-store, max-age=0`, and `Pragma: no-cache`.
- [ ] 2.3 Add exactly five Pest cases across `tests/Feature/CalendarFeedTest.php` (cases 2–4 here): unknown-token 404; unauthenticated valid multi-event feed with exact headers and past/future events; subscribed-only filtering plus parent/three-child materialized recurrence, four VEVENTs, no RRULE, and shared `build()` content.

## Phase 3: Dashboard, Documentation, and Regression

- [ ] 3.1 Modify `app/Livewire/Dashboard.php` and `resources/views/livewire/dashboard.blade.php`; lazily issue tokens, add `Calendar subscription`, read-only URL, `Copiar` via `navigator.clipboard`, and confirmed `Regenerar` warning that the old URL stops working.
- [ ] 3.2 Add case 5 to `tests/Feature/CalendarFeedTest.php`; verify lazy rendering, URL/copy markup, confirmation directive, and regeneration makes old URL 404/new URL 200.
- [ ] 3.3 Update `README.md` after iCalendar export with Google/Outlook subscription, past/future content, polling limits, compromise/regeneration guidance, and deferred per-class/RRULE/attendee/email scope; evidence: documented setup and limitations.
- [ ] 3.4 Run `php artisan test tests/Feature/CalendarFeedTest.php`, `php artisan test tests/Feature/IcalExportTest.php`, then `php artisan test`; verify zero new Composer dependencies and existing single-meeting export remains green.
