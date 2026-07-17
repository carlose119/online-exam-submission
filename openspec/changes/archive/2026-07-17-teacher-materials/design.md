# Design: Teacher Materials (Files, Links, Meetings)

## Technical Approach

Single Filament v5 `StudyMaterialResource` with a conditional form driven by a `type` Select (`FILE|LINK|MEETING`). Public render extends `JoinClassController` and `join.blade.php` (per `class-invitation-flow` spec ADDED requirements). Mirrors `ClassResource` conventions: `#[Fillable]` attributes, `Schema` API, `getEloquentQuery()` query-scope isolation to `teacher_id`, `Filament\Actions\*`. No new Composer dependencies required.

## Architecture Decisions

| Decision | Choice | Tradeoffs discarded | Rationale |
|---|---|---|---|
| Conditional form trigger | Filament `live()` + `afterStateUpdated` to reset `file_path_or_url` & `extra_metadata` | Alpine.js watchers, separate form pages per type | Native Filament v5 pattern: one form, no JS drift |
| Relationship name | `classroom()` belongsTo `SchoolClass` | `schoolClass()`, `class()` (reserved) | Matches existing codebase convention; avoids PHP keyword conflict |
| `StudyMaterialType` enum | PHP 8.1+ backed enum (`File`, `Link`, `Meeting`) with `getLabel()`, `getColor()`, `getIcon()` | Plain constants, config map | Typed enums = autocomplete + refactoring safety + native Filament `Select` support |
| Material ordering | `created_at DESC`, no explicit `order` column | Draggable reorder, weight column | PRD doesn't require reordering; simpler migration, simpler query |
| YouTube embed | Regex extraction `%(?:youtube\.com/(?:watch\?v=\|embed/)\|youtu\.be/)([\w-]{11})%i` in Blade, fallback `<a>` | Filament `reactive()` preview, JS embed | Server-side rendering — no JS dependency for public page |
| File upload disk | `public` disk, directory `materials/{class_id}` | S3, `local` disk with signed URLs | PRD §4.2: `storage/app/public/materials/{class_id}/`, public download via `Storage::url()` |
| No new packages | Filament v5.6.8 + Pest v4.7.5 + Laravel 13.19.0 cover everything | `livewire/livewire` (already installed), `spatie/valuestore` | FileUpload + responsive iframe + JSON cast = all built-in |

## Data Flow

```
Teacher (Filament /admin) ──→ StudyMaterialResource ──→ study_materials table
        │                                                    │
        │  type=FILE: FileUpload → public disk                │
        │  type=LINK: TextInput → file_path_or_url            │
        │  type=MEETING: TextInput + JSON → extra_metadata    │
        │                                                    │
Visitor (public /clase/unirse/{code})                         │
        │                                                    │
        └──→ JoinClassController::show                        │
                │                                             │
                ├──→ $class->studyMaterials()                │
                │       └── orderByDesc('created_at')         │
                │                                             │
                └──→ join.blade.php                           │
                        ├── FILE → <a download> via Storage::url()
                        ├── LINK → YouTube iframe OR <a target="_blank">
                        └── MEETING → card with join button
```

## File Changes

| File | Action | Description |
|---|---|---|
| `database/migrations/{ts}_create_study_materials_table.php` | Create | `study_materials` table: id, class_id FK cascade, title, type ENUM, file_path_or_url TEXT, extra_metadata JSON nullable, timestamps; index `(class_id, created_at)` |
| `app/Enums/StudyMaterialType.php` | Create | Backed enum `File|Link|Meeting` with `getLabel()`, `getColor()`, `getIcon()` |
| `app/Models/StudyMaterial.php` | Create | Eloquent model with `#[Fillable]`, `$casts`, `classroom()` belongsTo |
| `app/Models/SchoolClass.php` | Modify | Add `studyMaterials(): HasMany` |
| `app/Filament/Resources/StudyMaterialResource.php` | Create | Resource with conditional form (`live()` + `afterStateUpdated`), scoped query, table, copy-URL action |
| `app/Filament/Resources/StudyMaterialResource/Pages/{Create,Edit,List}StudyMaterial.php` | Create | Standard Filament page triples |
| `app/Http/Controllers/JoinClassController.php` | Modify | Pass `$materials` to view |
| `resources/views/class/join.blade.php` | Modify | Add Materials section AFTER TBD block |
| `tests/Feature/StudyMaterialResourceTest.php` | Create | CRUD, conditional fields, scope, mime/max-size validation, JSON round-trip (8 tests) |
| `tests/Feature/StudyMaterialPublicViewTest.php` | Create | Render per type, YouTube iframe, non-YouTube anchor, MEETING card, empty state (5 tests) |
| `tests/Feature/ClassInvitationFlowTest.php` | Modify | Extend with "Materials section renders after TBD block" scenario |
| `README.md` | Modify | Add "Teacher materials" section |

## Interfaces / Contracts

```php
// app/Enums/StudyMaterialType.php
enum StudyMaterialType: string {
    case File = 'FILE';
    case Link = 'LINK';
    case Meeting = 'MEETING';

    public function getLabel(): string { /* "File" / "Link" / "Meeting" */ }
    public function getColor(): string { /* "blue" / "green" / "amber" */ }
    public function getIcon(): string { /* heroicon name */ }
}
```

Form conditional pattern (StudyMaterialResource):
```php
Select::make('type')
    ->options(StudyMaterialType::class)
    ->live()
    ->afterStateUpdated(fn ($set) => $set->set('file_path_or_url', null)->set('extra_metadata', null))

FileUpload::make('file_path_or_url')
    ->visible(fn ($get) => $get('type') === StudyMaterialType::File->value)
    ->disk('public')->directory(fn ($get) => 'materials/'.$get('class_id'))
    ->acceptedFileTypes([...])->maxSize(50 * 1024)->downloadable()
```

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Feature (Pest v4) | `StudyMaterialResourceTest` — 8 scenarios | `actingAs($teacher)`, direct model assertions; `UploadedFile::fake()` for file tests; SQLite `:memory:` |
| Feature (Pest v4) | `StudyMaterialPublicViewTest` — 5 scenarios | `$this->get(route('class.join.show', $code))` + `assertSee()` / `assertDontSee()` |
| Feature (existing) | `ClassInvitationFlowTest` — extend 1 scenario | Append test: materials section renders after TBD block |

Tests written **after** implementation (not TDD per `config.yaml` `apply.tdd: false`). `RefreshDatabase` trait via `tests/Pest.php` applied to all Feature tests.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. Public route already exists; new Filament resource uses standard auto-discovered panel routing.

## Migration / Rollout

Single migration: `study_materials` table. No data migration. Rollback: `php artisan migrate:rollback` + delete new files + revert modified files to previous commit. The `class_user` pivot and student subscription remain deferred — materials are visible to anyone with the invitation link today.

## README Update

New section "Teacher materials: files, links, and meetings" appended after the "Teacher Classes & Invitation Flow" section. Covers: 3 material types, file upload limits (PDF/DOCX/XLSX/MP4, max 50MB), YouTube embed behavior, public visibility, deferred items (file cleanup, quotas, bulk upload, reordering), and `php.ini` 50MB requirement.

## Open Questions

None — all decisions resolved in proposal phase.
