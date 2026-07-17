# Tasks: Teacher Materials (Files, Links, Meetings)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 560–650 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: Data layer + Filament resource → PR 2: Public view + tests + README |
| Delivery strategy | ask-always |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes (resolved: stacked-to-main, PR 1 → main, then PR 2 → main)
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Data layer + Filament resource: enum, migration, model, relationship, full resource (form/table/pages) | PR 1 | `vendor/bin/pest --filter=StudyMaterialResourceTest` | `php artisan route:list --path=admin/study-materials` | Drop migration + delete enum/model/resource files + revert `SchoolClass::studyMaterials()` |
| 2 | Public view + tests + README: controller, blade, 14 test scenarios, docs | PR 2 | `vendor/bin/pest --filter="StudyMaterialPublicViewTest\|ClassInvitationFlowTest"` | Visit `/clase/unirse/{code}` in browser, verify Materials section | Revert controller/view/test files/README; PR 1 data layer stays |

PR 2 base target: PR 1 branch (feature-branch-chain) or main after PR 1 merges (stacked-to-main). Chain strategy pending user decision.

## Phase 1: Data Layer (Foundation)

- [x] 1.1 Create `app/Enums/StudyMaterialType.php` — backed enum with cases `File`, `Link`, `Meeting`; methods `getLabel()`, `getColor()`, `getIcon()`. Verify: `php -r "require 'vendor/autoload.php'; var_dump(App\Enums\StudyMaterialType::cases());"` prints all 3 cases.
- [x] 1.2 Create `database/migrations/2026_07_17_120000_create_study_materials_table.php` — columns: id, class_id FK cascade, title, type enum(FILE/LINK/MEETING), file_path_or_url text, extra_metadata json nullable, timestamps; index `(class_id, created_at)`. Verify: migration applied successfully.
- [x] 1.3 Create `app/Models/StudyMaterial.php` — `#[Fillable]` attribute, `$casts = ['extra_metadata' => 'array']`, `classroom()` belongsTo `SchoolClass`. Verify: tinker confirms `BelongsTo`.
- [x] 1.4 Add `studyMaterials(): HasMany` to `app/Models/SchoolClass.php` — return `$this->hasMany(StudyMaterial::class, 'class_id')`. Verify: tinker prints `OK`.

## Phase 2: Filament StudyMaterialResource (Core)

- [x] 2.1 Create `app/Filament/Resources/StudyMaterialResource.php` — Resource with conditional form (`Select::make('type')->live()->afterStateUpdated` to reset fields), `FileUpload` (public disk, acceptedFileTypes, 50MB max, visible on FILE), `TextInput` for LINK/MEETING URL, `class_id` Select searchable scoped via `getEloquentQuery()` to teacher's classes, table with searchable/sortable title, type Badge, class title, created_at; `getPages()` maps index/create/edit. Verify: `php artisan route:list --path=admin/study-materials` lists index/create/edit routes.
- [x] 2.2 Create `app/Filament/Resources/StudyMaterialResource/Pages/ListStudyMaterials.php` — extends `ListRecords`, binds resource. Verify: file exists at path.
- [x] 2.3 Create `app/Filament/Resources/StudyMaterialResource/Pages/CreateStudyMaterial.php` — extends `CreateRecord`, binds resource; packs `uploaded_file` → `file_path_or_url` and MEETING sub-fields → `extra_metadata` JSON. Verify: file exists at path.
- [x] 2.4 Create `app/Filament/Resources/StudyMaterialResource/Pages/EditStudyMaterial.php` — extends `EditRecord`, binds resource; header action "Copy public join URL" copying `route('class.join.show', $record->classroom->invitation_code)` via Notification (mirror `ClassResource` EditClass pattern). Verify: file exists at path + contains `copyInvitationLink` action name.

## Phase 3: Public View Extension (Integration)

- [ ] 3.1 Modify `app/Http/Controllers/JoinClassController.php` — add `use App\Models\StudyMaterial;` import; fetch `$materials = $class->studyMaterials()->orderByDesc('created_at')->get()`; pass `'materials' => $materials` to view. Verify: `grep 'materials' app/Http/Controllers/JoinClassController.php` returns 2 matches.
- [ ] 3.2 Extend `resources/views/class/join.blade.php` — after the `</div>` closing the `.action` div (line 78), add Materials section: guard `@if($materials->isNotEmpty())`; per-type rendering: FILE → download link via `Storage::url()`; LINK → YouTube regex `%(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([\w-]{11})%i` → iframe embed OR plain `<a target="_blank" rel="noopener">`; MEETING → card with title, formatted `scheduled_at`, "Join meeting" button. Add CSS for `.materials`, `.material-card`, `.meeting-card`, responsive iframe wrapper. Verify: `grep 'materials' resources/views/class/join.blade.php` returns matches.

## Phase 4: Pest Tests (Testing)

- [ ] 4.1 Create `tests/Feature/StudyMaterialResourceTest.php` — 8 scenarios matching spec: (a) create FILE with valid PDF, (b) create LINK with YouTube URL, (c) create LINK with non-YouTube URL, (d) create MEETING with metadata, (e) type change clears fields, (f) teacher A cannot access Teacher B's materials, (g) rejects disallowed MIME, (h) rejects oversized file. Use `actingAs`, `RefreshDatabase`, `UploadedFile::fake()`. Verify: `vendor/bin/pest --filter=StudyMaterialResourceTest` passes all 8.
- [ ] 4.2 Create `tests/Feature/StudyMaterialPublicViewTest.php` — 5 scenarios: (a) materials section renders after TBD block, (b) FILE renders as download link, (c) LINK YouTube renders iframe, (d) LINK non-YouTube renders plain anchor, (e) MEETING renders details with join button, (f) empty materials — section hidden. Verify: `vendor/bin/pest --filter=StudyMaterialPublicViewTest` passes all 6.
- [ ] 4.3 Extend `tests/Feature/ClassInvitationFlowTest.php` — add scenario: "materials section renders after TBD block" creating a class + 2 materials, asserting Materials section appears after TBD placeholder text. Verify: `vendor/bin/pest --filter=ClassInvitationFlowTest` passes existing 4 + new 1 = 5 tests.

## Phase 5: README (Documentation)

- [ ] 5.1 Add "Teacher materials: files, links, and meetings" section to `README.md` after "Teacher Classes & Invitation Flow" — covers: 3 types, file limits (PDF/DOCX/XLSX/MP4, 50MB), YouTube embed, public visibility, deferred items (file cleanup, quotas, reordering), php.ini 50MB note. Verify: `grep 'Teacher materials' README.md` returns match.

## Phase 6: Final Verification

- [ ] 6.1 Run full test suite: `php artisan test` — all tests pass (existing 31 + new ~15 = ~46 total). Verify: exit code 0.
- [ ] 6.2 Verify resource route auto-discovery: `php artisan route:list --path=admin/study-materials` lists `admin/study-materials`, `admin/study-materials/create`, `admin/study-materials/{record}/edit`. Verify: 3 routes listed.
- [ ] 6.3 Manual smoke: create teacher + class → log into `/admin/study-materials` → create one FILE, one LINK, one MEETING → visit `/clase/unirse/{code}` → Materials section renders all three types correctly.
