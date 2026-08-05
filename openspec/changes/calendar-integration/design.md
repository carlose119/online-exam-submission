# Design: Calendar Subscription Feed

## Technical Approach

Extend `live-class-meeting-management` R16–R20 with a public, bearer-token calendar feed. A nullable token is created on the authenticated student dashboard, the public controller resolves its owner and loads every meeting from subscribed classes, and `IcalBuilder` emits one calendar containing reused `VEVENT` serialization. Existing single-meeting export and materialized recurrence remain unchanged; no Composer dependency is added.

## Architecture Decisions

| Decision | Choice | Alternative / tradeoff | Rationale |
|---|---|---|---|
| Token storage | One nullable `VARCHAR(64)` unique index on `users.feed_token` | Separate feed table adds lifecycle and joins | One active feed per student makes overwrite immediate revocation. The unique constraint also provides the required lookup index; no redundant index is added. |
| Token issuance | Both public methods delegate to one private issuer using `Str::random(64)`; retry only unique-constraint collisions | Pre-check alone has a race | The database is authoritative under concurrent issuance. `generateFeedToken()` persists a token when invoked; the dashboard invokes it only when NULL. `regenerateFeedToken()` always overwrites, so the old URL immediately stops resolving. Non-collision database errors propagate. |
| Multi-event serialization | Extract the current event lines into a private helper; `build()` and `buildMany()` wrap those lines in one calendar envelope | Parsing and splicing `build()` output is brittle | UID, UTC dates, escaping, organizer, and optional values remain single-sourced. |
| Feed query | `subscribedClasses()->with('meetings.classroom.teacher')`, then flatten all meetings | Time scopes would omit required history | No date filter includes past/future rows and parent/child materialized instances while avoiding builder N+1 queries. |
| Dashboard mutation | Livewire `wire:click` action guarded by `wire:confirm`; copy uses `navigator.clipboard` | A separate POST controller duplicates component state | The existing dashboard is already behind `auth` + `role:STUDENT`; Livewire mutations use the web session and CSRF protection. |

## Data Flow

```text
Authenticated dashboard ──mount(NULL token)──> User::generateFeedToken()
        ├── Copy URL (browser only)
        └── Confirm regenerate ──CSRF-safe Livewire──> overwrite token

Calendar client ──GET /calendar/{token}.ics──> User lookup (404)
  ──> subscribed classes + meetings.classroom.teacher
  ──> IcalBuilder::buildMany() ──> inline, no-store/no-cache calendar response
```

## File Changes

| File | Action | Description |
|---|---|---|
| `database/migrations/{ts}_add_feed_token_to_users_table.php` | Create | Add `string('feed_token', 64)->nullable()->unique()`; `down()` drops its unique index and column. |
| `app/Models/User.php` | Modify | Add token generation/regeneration and collision retry; do not add the bearer token to fillable output. |
| `app/Services/IcalBuilder.php` | Modify | Add `buildMany(iterable): string` and shared private event/envelope helpers. |
| `app/Http/Controllers/CalendarFeedController.php` | Create | Token lookup, scoped eager load, build response. |
| `routes/web.php` | Modify | Add public `GET /calendar/{token}.ics` as `calendar.feed`, outside authenticated groups and with no explicit middleware. |
| `app/Livewire/Dashboard.php` | Modify | Lazy issuance and confirmed regeneration action. |
| `resources/views/livewire/dashboard.blade.php` | Modify | Read-only URL, “Copiar”, and “Regenerar” controls. |
| `tests/Feature/CalendarFeedTest.php` | Create | Five behavior-focused Pest tests. |
| `README.md` | Modify | Add subscription, polling, compromise/regeneration, and deferred-scope guidance after iCalendar export. |

## Interfaces / Contracts

- `User::generateFeedToken(): string`; `User::regenerateFeedToken(): string`.
- `IcalBuilder::buildMany(iterable $meetings): string`: exactly one `BEGIN/END:VCALENDAR`, one existing-format `VEVENT` per item, CRLF line endings.
- `CalendarFeedController::feed(string $token): Response`: unknown/rotated token → 404. Required success headers are `Content-Type: text/calendar; charset=utf-8`, `Content-Disposition: inline; filename="calendar.ics"`, `Cache-Control: no-store`, and `Pragma: no-cache`; the concrete `Cache-Control` value is `no-store, max-age=0`, preserving the required `no-store` directive while matching the proposal.

## Security / Privacy

The URL is a bearer credential exposing class meeting metadata. Application code must never log it; operational access logs should redact `/calendar/*`. HTTPS is required in deployment. Copy/regeneration UI warns that sharing leaks access and rotation disconnects every existing subscription. Tokens do not expire; client polling may delay visible updates despite `no-store`.

## Testing Strategy

After implementation (`strict_tdd: false`), Pest tests cover: unknown-token 404; unauthenticated valid feed with exact `Content-Type`, `Content-Disposition`, `Cache-Control: no-store, max-age=0`, and `Pragma: no-cache` headers, one envelope, and past/future/recurring rows; subscribed-only inclusion and unsubscribed exclusion; regeneration old-404/new-200; dashboard lazy generation, URL/copy rendering, and confirmation-marked action. Run the new file, `IcalExportTest.php`, then the full suite.

## Threat Matrix

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no executable classification | None | None |
| Git repository selection | N/A: no VCS integration | None | None |
| Commit state | N/A: no VCS integration | None | None |
| Push state | N/A: no VCS integration | None | None |
| PR commands | N/A: no PR automation | None | None |

The public routing boundary is covered by unauthenticated success, unknown/rotated-token 404, subscription isolation, and exact-header tests above.

## Migration / Rollout

The nullable column is safe for existing users and is lazily populated; no backfill or flag is required. Deploy migration before code. Rollback removes the route/UI/controller first, then drops the unique index and column; only feed credentials are lost. Calendar clients fail with 404 after rollback.

## Open Questions / Risks

- Calendar clients control polling cadence; README guidance must not promise immediate synchronization.
- Large materialized recurring series increase response size; RRULE remains explicitly deferred.
