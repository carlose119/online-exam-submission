# Proposal: Teacher Module — Classes, Invitation & Syllabus (teacher-module)

## Why (Intent)

The scaffold-and-admin change delivered a runnable platform and Admin-managed Teacher accounts, but teachers have no workspace yet. This change delivers the **first teacher-facing capability**: a teacher creates a class, writes its syllabus, and gets a public invitation link to share with students. It is the smallest slice that makes the LMS-Lite product usable from the teacher side and unblocks every subsequent module (materials, exams, reports all attach to a `class`). Crucially, it also stands up the **public invitation entry point** — the surface the future Student module will plug into — while being honest that actual student subscription (`class_user` pivot) is not implemented in this slice and the join page shows a "TBD" placeholder.

Builds on canonical specs:
- `openspec/specs/platform-scaffold/spec.md` — Laravel 13 / Filament v5 / Livewire v3 skeleton, role-gated `/admin` panel, `users` table with `role`/`suspended_at`.
- `openspec/specs/admin-teacher-management/spec.md` — Admin CRUD over Teacher accounts that this change's authenticated teachers come from.

## What Changes (Scope)

### In Scope
- Migration: `classes` table per PRD §5.2 — `id`, `teacher_id` FK→`users`, `title`, `description` (nullable text), `syllabus` (nullable longText), `invitation_code` (unique string), timestamps.
- `SchoolClass` Eloquent model: `teacher()` belongsTo `User`, `students()` belongsToMany `User` (relationship **declared**; `class_user` pivot is deferred, so the relation is documented but unused this slice). The PHP class name is `SchoolClass` (PHP rejects `class` as a class name); the table is `classes`.
- `ClassResource` (Filament v5) under the existing `/admin` panel: list, create, edit, delete.
  - Form schema: `title`, `description`, `syllabus` (Filament `RichEditor`), `teacher_id` hidden defaulting to `Auth::id()`.
  - Table columns + actions scoped so a teacher sees ONLY classes where `teacher_id = Auth::id()` (query scope / `getEloquentQuery`).
- `invitation_code` auto-generated on create (`Str::random(8)`, uniqueness retry-on-collision), **not** user-editable; a "Regenerate invitation code" Filament Action available on edit.
- Public Laravel route `clase/unirse/{invitation_code}` (NOT in the Filament panel) + `JoinClassController` rendering a simple Blade view: class title, description, syllabus.
  - Auth-aware affordance: authenticated user → "Join class" button that renders a **"Class joined (TBD)" placeholder** (subscription intentionally NOT wired); unauthenticated → "Log in to join" linking to `/admin/login`.
- "Copy invitation link" Filament Action on the class edit page (JS `CopyToClipboard` + Filament `Notification` confirmation).
- Pest tests (SQLite `:memory:`): teacher creates a class and a unique `invitation_code` is auto-generated; Teacher A cannot see/edit Teacher B's classes; public invitation URL is reachable without auth and shows the class; a teacher can trigger the copy-link action.
- README update: new "Teacher classes & invitation flow" section.

### Out of Scope / Future Work
- `study_materials` table + materials CRUD (FILE / LINK / MEETING) and the `storage/app/public/materials/{class_id}/` directory.
- `exams`, `questions`, `answer_options` tables + exam builder (Filament Repeater).
- `class_user` pivot table + **actual student subscription** — the public route shows a "TBD" placeholder, not a real join.
- `student_attempts`, `student_answers` + auto-grading (strict multiple-choice rule).
- Reports (PDF + Excel via `barryvdh/laravel-dompdf` + `maatwebsite/excel`).
- Student module (registration, login, dashboard, exam taking) and server-side exam timer.
- Live-class materialization (meeting URLs with date/time).

## Capabilities

> Contract for sdd-spec. Existing canonical specs in `openspec/specs/` are **referenced, not modified** (no spec-level behavior change to scaffold or admin).

### New Capabilities
- `teacher-class-management`: Filament v5 `ClassResource` CRUD scoped to the authenticated teacher (`teacher_id = Auth::id()`), `SchoolClass` model + `classes` migration, automatic `invitation_code` generation + regenerate, syllabus via `RichEditor`, copy-invitation-link action.
- `class-invitation`: Public (unauthenticated) route `clase/unirse/{invitation_code}` rendering class details with an auth-aware join affordance; subscription is a documented TBD placeholder this slice.

### Modified Capabilities
- None. `platform-scaffold` and `admin-teacher-management` specs are unchanged at the requirement level.

### Future Capabilities Enabled by This Slice
- `class-student-subscription` — real `class_user` pivot + join logic behind the existing public route.
- `study-materials` — File/Link/Meeting materials per `class_id`.
- `exam-builder` — exams/questions/answer_options via Filament Repeater, attached to `classes`.
- `student-exam-taking` — Livewire exam UI + server-side timer + auto-submit.
- `auto-grading` — strict single/multiple-choice scoring.
- `teacher-reports` — PDF/Excel evaluation-plan and gradebook exports.
- `student-onboarding` — student registration via invite link + student Livewire dashboard.

## Impact (Affected Areas)

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/*_create_classes_table.php` | New | `classes` schema per PRD §5.2 |
| `app/Models/SchoolClass.php` | New | `teacher()`, `students()` (declared, pivot deferred). PHP rejects `class` as class name. |
| `app/Filament/Resources/ClassResource.php` | New | Teacher-scoped CRUD + invitation code + copy action |
| `app/Http/Controllers/JoinClassController.php` | New | Public invite route controller |
| `resources/views/classes/join.blade.php` | New | Public class details + TBD join placeholder |
| `routes/web.php` | Modified | `clase/unirse/{invitation_code}` public route |
| `tests/Feature/ClassResourceTest.php` (Pest) | New | CRUD scoping, invitation code, public route, copy action |
| `README.md` | Modified | "Teacher classes & invitation flow" section |

## Approach

Keep the teacher inside the existing Filament `/admin` panel (CheckRole already gates it to `ADMIN,TEACHER`); no new panel. Teacher isolation is enforced by a **per-resource query scope** (`getEloquentQuery()->where('teacher_id', Auth::id())`) plus a hidden `teacher_id` defaulting to `Auth::id()` on create — no extra Gates. The public invitation page is a plain **Laravel route + Blade view** (not Livewire): it is mostly static, and Livewire earns its keep only once interactive join/subscription arrives in a future slice. `invitation_code` uses `Str::random(8)` (alphanumeric, ~36⁸ space) with a retry loop on collision; `syllabus` uses Filament `RichEditor` for non-technical teachers. Class deletion is **restrict** (no cascade) since subscriptions are deferred; revisit cascade policy when `class_user` lands. The PHP model class is named `SchoolClass` (PHP 8.4.4 rejects `class` as a class name — reserved keyword). The table name remains `classes` per the PRD schema; only the PHP class name was renamed. The Filament resource remains `ClassResource` (resource class names do not need to match the model name).

### Open Technical Questions — Resolved
- **Public route framework**: Blade view (not Livewire) — page is static this slice.
- **Invitation code**: `Str::random(8)` with collision retry.
- **Syllabus editor**: Filament `RichEditor` (WYSIWYG) over `MarkdownEditor`.
- **Teacher authorization**: per-resource query scope to `teacher_id = Auth::id()` (no extra Gate).
- **Class deletion**: `restrict`, no cascade (subscriptions deferred).
- **Copy button UX**: Filament Action + JS `CopyToClipboard` + `Notification`.
- **`SchoolClass` model name**: PHP requires the rename; the table `classes` is retained.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Public route exposes class title/description to anyone with the code (no auth, non-guessable code is the only gate) | Low–Med | Document as intended product behavior in spec + README; code is non-guessable; no PII exposed (no student data yet); revisit once syllabus may include sensitive material |
| "TBD" join placeholder misleads teachers into thinking subscription works | Med | UI clearly labels the button as "TBD" / "Class joined (preview)"; spec scenario asserts the placeholder text; README documents the deferred status |
| Filament v5 `RichEditor` (Trix-based) stability / output sanitization across re-renders | Low | Use stock input; store raw HTML; revisit sanitization if XSS risk grows with student viewers |
| `invitation_code` collision with `Str::random(8)` (~2.8e12 space) | Low | Retry-on-collision loop in generation + DB unique index backstop |
| Teacher A reaches Teacher B's class admin/edit URL by guessing IDs | Med | `getEloquentQuery` scope rejects (404/403); Pest test covers cross-teacher access |

## Rollback Plan

Revert the change in order: drop `class_user`-free `classes` migration (`rollback`), delete `SchoolClass` model, `ClassResource`, `JoinClassController`, `join.blade.php`, the public route, and the new Pest tests. Restore `routes/web.php` and `README.md` from the pre-change commit. The platform-scaffold and admin-teacher-management specs and data are untouched, so the admin panel and teacher accounts remain fully functional. No data migration is needed (no production `classes` rows expected in this first slice).

## Dependencies

- Canonical specs: `openspec/specs/platform-scaffold/spec.md`, `openspec/specs/admin-teacher-management/spec.md` (already archived).
- Stack installed by scaffold-and-admin: Laravel 13, Filament v5.6.8, Livewire v4.3.3, MariaDB 10.11.9 (`mysql` driver), Pest v4.7.5 with SQLite `:memory:`.
- `CheckRole:ADMIN,TEACHER` middleware already registered in `AdminPanelProvider->authMiddleware()` — no panel/middleware changes needed.
- `User` model `role` enum + `suspended_at` + hashed `password` cast already in place.

## Success Criteria

- [ ] A teacher creates a class and a unique `invitation_code` is auto-generated (not editable in the form).
- [ ] Teacher A cannot list, view, or edit Teacher B's classes (scoped query + URL protection).
- [ ] A teacher can edit a class syllabus via the RichEditor and the content persists.
- [ ] The public route `clase/unirse/{invitation_code}` is reachable **without auth** and renders the class title, description, and syllabus.
- [ ] The join button is honestly labeled as a TBD placeholder (subscription not wired this slice).
- [ ] "Copy invitation link" action copies the full URL and confirms via Notification.
- [ ] Pest tests pass on SQLite `:memory:`; README documents the teacher flow and the deferred subscription.
- [ ] No changes to `openspec/specs/platform-scaffold` or `openspec/specs/admin-teacher-management`.