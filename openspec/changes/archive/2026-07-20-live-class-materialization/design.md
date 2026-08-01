# Design: Live Class Materialization

## Technical Approach

Single-table addition (`meetings`) surfaced in two places: a Filament `MeetingResource` for teacher/admin scheduling, and a "Próximas clases en vivo" section on the student dashboard. The change introduces the project's first datetime-based logic — UTC storage, Carbon display, ±15 min live-window scope — establishing patterns for all future time-bound features. Zero new Composer dependencies. Builds on `teacher-class-management` (R1–R5) for class ownership and `student-class-subscription` (R1–R9) for subscription-based dashboard queries.

## Architecture Decisions

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| D1 | Teacher-access scoping | `whereHas('classroom', fn($q) => $q->where('teacher_id', Auth::id()))` per `ExamResource` | Matches existing pattern; avoids nested dot-notation `classroom.teacher` that diverges from codebase conventions |
| D2 | Admin-override strategy | `unless(Auth::user()?->role === 'ADMIN', fn($q) => …)` per `ClassReportResource` | Explicit role check before scoping matches existing admin fallthrough pattern; readable and testable |
| D3 | Join-window single source of truth | `Meeting::scopeLive()` on the model, reused by Filament `->visible()` callback | Avoids duplicate logic between query builder and table rendering |
| D4 | Dashboard query shape | `subscribedClasses()->with(['meetings' => upcoming()->limit(5)])` | Eager-loads only future meetings per class; `limit(5)` in the relationship closure constrains total results |
| D5 | Past badge vs scheduled_at column | Separate `TextColumn` with `->badge()` and computed state | Splits display concern from data column; gray "Past" badge renders only when `scheduled_at < now()` per spec R5 |
| D6 | ViewMeeting page approach | Custom page extending `ViewRecord` with inline `Infolist` | Simpler than a full View page with separate widgets; `ViewRecord` is the Filament v5 convention for read-only detail views |

## Data Flow

```
Teacher ──► MeetingResource (Filament CRUD) ──► meetings table (UTC)
                                                   │
Student ──► Dashboard Livewire ◄── subscribedClasses() + upcoming scope
                │
                ▼
         dashboard.blade.php: "Próximas clases en vivo" cards
              └── Join button (meeting_url, new tab, window check)
              └── diffForHumans() + format('M j, g:i A T')
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*_create_meetings_table.php` | Create | id, class_id FK→classes cascade, title(255), scheduled_at(timestamp), duration_minutes(int default 60), meeting_url(text nullable), agenda(text nullable), timestamps |
| `app/Models/Meeting.php` | Create | `#[Fillable]` 6 fields; casts: `scheduled_at→datetime`, `duration_minutes→integer`; `classroom()` BelongsTo; scopes: `upcoming`, `past`, `live` |
| `app/Models/SchoolClass.php` | Modify | Add `meetings(): HasMany` relationship |
| `app/Filament/Resources/MeetingResource.php` | Create | Resource with role-scoped `getEloquentQuery()`, form, table (columns + Join Action + Past badge), navigation |
| `app/Filament/Resources/MeetingResource/Pages/{ListMeetings,CreateMeeting,EditMeeting,ViewMeeting}.php` | Create | 4 page stubs; ViewMeeting uses `Infolist` for read-only display with conditional Join button |
| `app/Filament/Resources/ClassResource.php` | Modify | Add `meetings_count` BadgeColumn via `withCount('meetings')` in `getEloquentQuery()` |
| `app/Livewire/Dashboard.php` | Modify | Add `$upcomingMeetings` query: subscribed classes → meetings `upcoming()→limit(5)`, ordered `scheduled_at ASC` |
| `resources/views/livewire/dashboard.blade.php` | Modify | Add "Próximas clases en vivo" section with cards: title, class name, formatted datetime, "Live now!" indicator, Join button |
| `tests/Feature/MeetingResourceTest.php` | Create | CRUD scoping (teacher/admin), cross-teacher 403, join window boundaries, past badge, cascade delete, model scopes |
| `tests/Feature/StudentDashboardTest.php` | Modify | Extend with: upcoming meetings ordering, empty state, auth gate, subscription isolation, "Live now!" indicator, Join button |
| `README.md` | Modify | Add "Live class materialization: meeting scheduling" section after Reports |

## Interfaces / Contracts

**Meeting model scopes** — single source of truth for time logic:

```php
// Eloquent query scope
public function scopeLive(Builder $query): void
{
    $query->whereNotNull('meeting_url')
        ->where('scheduled_at', '<=', now()->addMinutes(15))
        ->where('scheduled_at', '>=', now()->subMinutes(15));
}

// Instance check (for ViewMeeting / dashboard cards)
public function isLive(): bool
{
    return $this->meeting_url !== null
        && $this->scheduled_at->gte(now()->subMinutes(15))
        && $this->scheduled_at->lte(now()->addMinutes(15));
}
```

**Join Action visibility** (Filament table row):
```
->visible(fn (Meeting $record) => $record->isLive())
```

**Dashboard join button**: same `isLive()` check in Blade; renders `<a target="_blank" href="{{ $meeting->meeting_url }}">`.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature (Pest) | MeetingResource CRUD: teacher scoping, admin bypass, cross-teacher rejection, cascade delete | `Auth::login($teacher)` + `MeetingResource::getEloquentQuery()` pattern from `ExamResourceTest`; `Livewire::test(CreateMeeting::class)` for form rendering |
| Feature (Pest) | Join-window boundaries: -16 min (disabled), -15 min (enabled), 0 min (enabled), +14 min (enabled), +16 min (disabled) | `Carbon::setTestNow()` frozen at each boundary; check `->isLive()` and Action `->visible()` state |
| Feature (Pest) | Past badge: gray when `scheduled_at < now()`, absent when future | Frozen time with meetings in past/future; assert badge visibility |
| Feature (Pest) | Dashboard: upcoming ordering, limit 5, empty state, subscription isolation, "Live now!" indicator, Join button | Extend existing `StudentDashboardTest`; `actingAs($student)` with meetings created in subscribed/unsubscribed classes |
| Feature (Pest) | ClassResource meetings-count badge: "0 meetings" and "3 meetings" | Extend existing ClassResourceTest or inline in MeetingResourceTest |

Tests are written AFTER implementation per `openspec/config.yaml` `rules.apply.tdd: false`. Time-frozen tests use `Carbon::setTestNow()` with `RefreshDatabase` on SQLite `:memory:`.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration required beyond the new `meetings` table migration. Rollback: `php artisan migrate:rollback` drops the table; remove the 8 new files and revert the 6 modified files.

## Open Questions

None — all technical decisions resolved in the proposal. The design is ready for task breakdown.
