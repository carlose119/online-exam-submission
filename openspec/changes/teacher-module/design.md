# Design: Teacher Module — Classes, Invitation & Syllabus

## Technical Approach

A new Filament v5 `ClassResource` inside the existing `/admin` panel (gated by `CheckRole:ADMIN,TEACHER` from `platform-scaffold`) gives teachers CRUD over their own classes. Teacher isolation uses `getEloquentQuery()->where('teacher_id', Auth::id())` — same pattern as `TeacherResource`. A public Laravel route `GET /clase/unirse/{invitation_code}` renders a minimal Blade view (no Livewire, no auth) showing class details with a TBD subscription placeholder. Builds on `admin-teacher-management` for teacher accounts.

## Architecture Decisions

| Decision | Tradeoff | Choice |
|---|---|---|
| Public route framework | Blade: static page, zero reactivity needed. Livewire earns its keep when subscription becomes interactive. | Blade view via `JoinClassController` |
| Invitation code | 8 chars: ~2.8e12 space, human-readable. UUID overkill for manual sharing. | `Str::random(8)` with retry (max 5) |
| Syllabus editor | RichEditor: WYSIWYG for non-technical teachers. Markdown requires literacy. | `RichEditor` |
| Teacher authz | Query scope covers list/view/edit/delete uniformly. Gate adds indirection without benefit. | `getEloquentQuery()` scope only |
| Class deletion FK | Cascade premature — no `class_user` pivot yet. Revisit when subscription lands. | `onDelete('restrict')` |
| Copy button | HeaderAction copies full URL (JS clipboard). Table `copyable()` copies code only. Both useful. | Both: HeaderAction + `copyable()` column |
| Model name | PHP 8.4.4 rejects `class` as a class name (reserved keyword). Rename to `SchoolClass` (table stays `classes`). | `SchoolClass` (table `classes`) |

## Data Flow

```
Teacher ──→ /admin/classes (Filament List)
                │ getEloquentQuery() → WHERE teacher_id = Auth::id()
                ▼
         ClassResource CRUD ──→ classes table
                │
                ▼
         Copy invitation link ──→ clipboard (JS) + Notification
                │
                ▼
    Public visitor ──→ /clase/unirse/{code}
                │ JoinClassController::show()
                ▼
         class/join.blade.php ──→ renders title, description, syllabus, TBD button
```

## File Changes

| File | Action | Description |
|---|---|---|
| `database/migrations/..._create_classes_table.php` | Create | `classes`: id, teacher_id FK→users(restrict), title VARCHAR(255), description TEXT nullable, syllabus LONGTEXT nullable, invitation_code VARCHAR(12) unique, timestamps |
| `app/Models/SchoolClass.php` | Create | Eloquent model with `#[Fillable]`, `teacher()` belongsTo, `students()` documented but unwired (table `classes`) |
| `app/Filament/Resources/ClassResource.php` | Create | CRUD: form (title, description, RichEditor syllabus, hidden teacher_id), table (title, description truncated, teacher.name, invitation_code copyable Badge), actions (Edit, Delete, regenerateCode, copyLink) |
| `app/Filament/Resources/ClassResource/Pages/ListClasses.php` | Create | List page stub extending `ListRecords` |
| `app/Filament/Resources/ClassResource/Pages/CreateClass.php` | Create | Create page; `mutateFormDataBeforeCreate()` sets teacher_id + generates invitation_code |
| `app/Filament/Resources/ClassResource/Pages/EditClass.php` | Create | Edit page; `getHeaderActions()` → copyInvitationLink, regenerateInvitationCode |
| `app/Http/Controllers/JoinClassController.php` | Create | `show(Request, string $invitationCode)`: find by code or 404, passes class + `isAuthenticated` + `loginUrl` to view |
| `resources/views/class/join.blade.php` | Create | Minimal Blade: class details, auth-aware affordance (guest→login link; authenticated→TBD placeholder) |
| `tests/Feature/ClassResourceTest.php` | Create | Pest: CRUD scoping, auto-generated code, cross-teacher 404, regenerate, copy action |
| `tests/Feature/ClassInvitationFlowTest.php` | Create | Pest: public route 200/404, syllabus HTML, TBD placeholder, login link |
| `routes/web.php` | Modify | Add public route: `GET /clase/unirse/{invitation_code}` → `JoinClassController@show` |
| `README.md` | Modify | Add "Teacher classes & invitation flow" section |

## Interfaces / Contracts

### SchoolClass Model (Eloquent attributes)

```php
#[Fillable(['title', 'description', 'syllabus', 'teacher_id', 'invitation_code'])]
class SchoolClass extends Model
{
    protected $table = 'classes';  // table name retained; only the PHP class name was renamed

    public function teacher(): BelongsTo;
    // students() belongsToMany deferred — class_user pivot not yet created
}
```

### Invitation Code Generation (retry loop)

```php
$code = Str::random(8);
$attempts = 0;
while (SchoolClass::where('invitation_code', $code)->exists() && $attempts < 5) {
    $code = Str::random(8); $attempts++;
}
// Throw RuntimeException if $attempts >= 5
```

### Copy Invitation Link (EditClass HeaderAction)

Uses `$this->js()` (Livewire) to copy `route('class.join.show', $record->invitation_code)` full URL to clipboard, then shows persistent `Notification` with the URL as fallback.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Feature | Teacher CRUD scoping | `actingAs($teacher)` → create, list, edit, delete own; cross-teacher edit → 404 |
| Feature | Invitation code | Two creates → different codes; regenerate → different code |
| Feature | Public route | Guest GET valid code → 200 with title; invalid → 404; syllabus HTML present |
| Feature | Auth-aware affordance | Guest → login link; authenticated → TBD placeholder text |
| Feature | Copy action | HeaderAction exists on edit page; table column is copyable |

Tests use SQLite `:memory:` + `RefreshDatabase` (configured in `tests/Pest.php`). Written AFTER implementation, not test-first.

## Threat Matrix

N/A — no shell commands, subprocesses, VCS/PR automation, executable-file classification, or process-integration boundary. The public route is a read-only GET with no write side-effects, no file upload, and no command execution.

## Migration / Rollout

No migration required beyond `php artisan migrate`. Rollback: `php artisan migrate:rollback` (drops `classes` table), delete new files, revert `routes/web.php` and `README.md`. Zero production data risk — this is the first user of the `classes` table.

## Open Questions

- None. All technical decisions resolved in the proposal phase.
