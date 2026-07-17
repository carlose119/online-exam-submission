# Proposal: Teacher Materials (Files, Links, Meetings)

## Intent (Why)

Teachers currently have classes (see `teacher-class-management`) and a public invitation page (see `class-invitation-flow`), but no way to attach learning content to a class. PRD §3.2 requires three material kinds: uploaded files, external links (e.g. YouTube), and live class meetings (URL + metadata). This change adds the first `study_materials` slice so a teacher can publish content and any visitor with the invitation link can view it.

## What Changes

- New `study_materials` migration per PRD §5.4: `id`, `class_id` FK → `classes.id` `onDelete('cascade')`, `title`, `type` enum [FILE, LINK, MEETING], `file_path_or_url` text, `extra_metadata` JSON nullable, timestamps.
- New `StudyMaterial` Eloquent model: `#[Fillable]` PHP attribute, `extra_metadata` cast to array, `classroom()` belongsTo `SchoolClass` (named `classroom()` because the PHP class is `SchoolClass`).
- New `StudyMaterialResource` (Filament v5, `/admin` panel, matches `ClassResource` conventions):
  - Conditional form driven by `type` Select with `live()` + `afterStateUpdated` to reset `file_path_or_url`: FILE → `FileUpload` (`disk('public')`, PDF/DOCX/XLSX/MP4, 50 MB); LINK → URL `TextInput`; MEETING → URL `TextInput` + `extra_metadata` group with `meeting_title` + `scheduled_at` (DateTimePicker).
  - `class_id` Select searchable from the teacher's own classes; `getEloquentQuery()` scoped via `whereHas('classroom', teacher_id = Auth::id())`.
  - Table: title (searchable/sortable), `type` Badge (FILE blue / LINK green / MEETING amber), class title (relationship), `created_at` (sortable, toggleable). Edit, Delete, and header "Copy public join URL" action mirroring `ClassResource`.
- Extend `JoinClassController::show` to pass `materials = $class->studyMaterials()->orderByDesc('created_at')->get()`.
- Extend `resources/views/class/join.blade.php` with a new "Materials" section AFTER the existing TBD block (no restructuring). FILE → download link; LINK → YouTube iframe embed (11-char ID via regex) or plain anchor; MEETING → title, `scheduled_at`, "Join meeting" button.
- Pest tests: `StudyMaterialResourceTest` (CRUD per type, conditional fields, query-scope isolation, mime rejection, JSON round-trip) and `StudyMaterialPublicViewTest` (render path per type, embed vs anchor).
- README: new "Teacher materials: files, links, and meetings" section.

## Capabilities

### New Capabilities
- `teacher-materials`: Teacher-scoped CRUD over materials (files/links/meetings) attached to a teacher's class, plus public rendering on the invitation page.

### Modified Capabilities
- `class-invitation-flow`: The public `/clase/unirse/{invitation_code}` page additionally renders a "Materials" section after the existing TBD block, listing the class's materials ordered `created_at DESC`.

## Approach

Single conditional Filament v5 form (one resource, three `type` branches) over one `study_materials` table. Public render is server-side Blade; YouTube embedding uses regex extraction in Blade, falling back to a plain `<a>` for non-YouTube. Mirrors `ClassResource` style (PHP attributes, `Schema`, `Filament\Actions\*`, query-scope auth).

## Impact

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/*_create_study_materials_table.php` | New | `study_materials` table with cascade FK to `classes`. |
| `app/Models/StudyMaterial.php` | New | Eloquent model, Fillable attribute, casts, `classroom()`. |
| `app/Models/SchoolClass.php` | Modified | Add `studyMaterials()` hasMany. |
| `app/Filament/Resources/StudyMaterialResource.php` | New | Conditional form, scoped table, copy-URL action. |
| `app/Http/Controllers/JoinClassController.php` | Modified | Pass `$materials` to view. |
| `resources/views/class/join.blade.php` | Modified | Append "Materials" section after TBD block. |
| `tests/Feature/StudyMaterialResourceTest.php` | New | CRUD, conditional fields, scope, mime, JSON. |
| `tests/Feature/StudyMaterialPublicViewTest.php` | New | Public render per type. |
| `README.md` | Modified | New materials section. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Orphan files on disk after class/material deletion (cascade deletes rows only, not files) | Med | Document as follow-up; defer file cleanup to a future change. |
| Public visibility — anyone with invitation code can download/view files | Low | Intended per product decision; document in README and spec. |
| YouTube iframe XSS | Low | Only YouTube domains are embedded (regex-extracted 11-char ID); non-YouTube falls back to plain `<a>` with `rel="noopener" target="_blank"`. No `srcdoc`/arbitrary HTML. |
| `php.ini` 50 MB upload limit (`upload_max_filesize`/`post_max_size`) not configured | Med | Document required settings in README and verify task; Filament validation rejects oversized files regardless. |
| Mime allowlist too narrow (teachers want PPT, images, etc.) | Low | Document current allowlist (PDF/DOCX/XLSX/MP4); extension is a follow-up, not in this slice. |
| Multiple teachers' materials list could be empty/degraded if `SchoolClass` relationship name changes | Low | Pin relationship name `classroom()` in model + tests. |

## Rollback Plan

- Drop `study_materials` table via `php artisan migrate:rollback`.
- Delete `StudyMaterial` model, `StudyMaterialResource`, the two Pest test files.
- Revert `JoinClassController::show` and `resources/views/class/join.blade.php` to the previous commit (remove the Materials section).
- Remove `studyMaterials()` from `SchoolClass` model.
- No data outside the new table is affected; rollback leaves classes and invitation flow intact.

## Dependencies

- `teacher-class-management` (materials belong to a teacher-owned class; query-scope pattern reuses `class_id` + `teacher_id`).
- `class-invitation-flow` (public page rendered by `JoinClassController`).
- `config/filesystems.php` `public` disk + `public/storage` symlink (already present from PR 1).
- Filament v5, Livewire v3+ stack already installed

## Future Capabilities Enabled

- Student module: subscribed students see materials scoped to their enrolled classes (today: anyone-with-code).
- Exam engine: reuse conditional Filament form pattern and `extra_metadata` JSON column shape for `questions.answer_options`.
- Reports (PDF + Excel): `barryvdh/laravel-dompdf` + `maatwebsite/excel` materialization.
- Scheduled-classes feature: extend MEETING type with recurring/calendar invites.

## Success Criteria

- [ ] A teacher can create FILE, LINK, and MEETING materials via one conditional form.
- [ ] Teacher A cannot see/edit Teacher B's materials (query scope).
- [ ] Public invitation page lists materials grouped per type; YouTube links embed as iframes.
- [ ] MIME validation rejects disallowed file types; oversized files rejected.
- [ ] `extra_metadata` JSON round-trips for MEETING materials.
- [ ] Deleting a class cascades to its materials at the DB level.
- [ ] Pest tests pass for resource and public-view paths.