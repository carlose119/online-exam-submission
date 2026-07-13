# Archive Report: teacher-module

## Change Overview

**Original intent**: The scaffold-and-admin change delivered a runnable platform and Admin-managed Teacher accounts, but teachers had no workspace yet. This change delivered the **first teacher-facing capability**: a teacher creates a class, writes its syllabus, and gets a public invitation link to share with students. It is the smallest slice that makes the LMS-Lite product usable from the teacher side and unblocks every subsequent module (materials, exams, reports all attach to a `class`). It also stood up the **public invitation entry point** — the surface the future Student module will plug into — while honestly marking actual student subscription (`class_user` pivot) as deferred with a "TBD" placeholder on the join page.

## What Was Delivered

| Metric | Count |
|--------|-------|
| Capabilities archived | 2 |
| Spec requirements | 8 (5 teacher-class-management + 3 class-invitation-flow) |
| Spec scenarios | 13 (9 + 4) |
| Implementation tasks completed | 13 |
| New files created | 10 |
| Modified files | 2 (`routes/web.php`, `README.md`) |
| Tests created (Pest) | 13 (9 ClassResourceTest + 4 ClassInvitationFlowTest) |
| Total tests passing | 31 (18 existing + 13 new) |

## Verify Verdict

**PASS WITH WARNINGS** (verified 2026-07-13)

- 8/8 spec requirements pass
- 31/31 tests pass (57 assertions)
- 0 CRITICAL findings
- 2 WARNINGs (both now closed):
  1. **Untracked OpenSpec artifacts** — resolved by commit `b42e386` which committed `proposal.md` and `specs/` to git.
  2. **Proposal "Class retained" assertion** — resolved by commit `b42e386` which corrected lines 70 and 79 in `proposal.md` to reflect the `SchoolClass` rename.

## SchoolClass Deviation Narrative

The original design asserted that PHP accepts `class Class extends Model` as a valid class name. This was **incorrect** — `class` is a reserved keyword in **all PHP versions including 8.4.4**, and attempting to define `class Class` produces a parse error.

**Resolution**: The model was implemented as `app/Models/SchoolClass.php` with `protected $table = 'classes'`. The table name `classes` was retained per the PRD schema; only the PHP class name changed. The Filament resource remains `ClassResource` because resource class names do not need to match model names (Filament resolves them by resource reference, not by model class name).

**Correction trail**:
- Design corrected in commit `18dac5f` ("docs(teacher-module): correct design for SchoolClass model rename")
- Proposal corrected in commit `b42e386` ("docs(teacher-module): commit OpenSpec artifacts and correct proposal for SchoolClass rename")

## Merge Evidence

All 8 commits are on local `master` (NOT yet pushed to origin):

| Hash | Message |
|------|---------|
| `fb0885e` | feat: add classes migration with invitation_code, syllabus, and teacher FK restrict |
| `cd04c65` | feat: add ClassResource with CRUD, query-scope auth, RichEditor, and code auto-generation |
| `b2eef22` | feat: add public join route, JoinClassController, and Blade view with auth-aware TBD placeholder |
| `41bf55d` | test: add ClassResourceTest (9 scenarios) and ClassInvitationFlowTest (4 scenarios) |
| `fa12dc7` | docs: add Teacher classes & invitation flow section to README |
| `0ea650a` | chore: mark teacher-module tasks as done in OpenSpec tasks.md |
| `18dac5f` | docs(teacher-module): correct design for SchoolClass model rename |
| `b42e386` | docs(teacher-module): commit OpenSpec artifacts and correct proposal for SchoolClass rename |

The change is functional locally. The user will push to origin when ready.

## Archived Capabilities

### teacher-class-management (5 requirements, 9 scenarios)
1. **Teacher-Scoped Class CRUD** — Filament `ClassResource` under `/admin`, scoped to `teacher_id = Auth::id()`. 5 scenarios: list own, create, edit, delete, cross-teacher 404.
2. **Invitation Code Auto-Generation** — `Str::random(8)` with collision retry (max 5). 1 scenario.
3. **Invitation Code Regeneration** — HeaderAction on edit page. 1 scenario.
4. **Syllabus RichEditor Storage** — `longText` column via Filament `RichEditor`. 1 scenario.
5. **Copy Invitation Link Action** — JS clipboard + Filament Notification. 1 scenario.

### class-invitation-flow (3 requirements, 4 scenarios)
1. **Public Invitation Route** — `GET /clase/unirse/{invitation_code}` renders class details. 1 scenario.
2. **Auth-Aware Join Affordance** — Guest → login link; Authenticated → TBD placeholder (no subscription). 2 scenarios.
3. **Nonexistent Invitation Code** — Invalid code returns 404. 1 scenario.

## Deviations

- **SchoolClass rename** (from original design `Class`) — documented above. This is the only deviation from the proposal and design.
- **`students()` belongsToMany declared but unwired** — The relationship was declared in `SchoolClass.php` but the `class_user` pivot table does not exist. The join page shows a "TBD" placeholder. This is by design (deferred to a future `class-student-subscription` slice).

## Next Steps for the Project

The recommended next change is **`teacher-materials`**: add the `study_materials` table (columns: `id`, `class_id` FK→classes, `type` ENUM(FILE/LINK/MEETING), `title`, `description`, `url`, `file_path`, `order`, timestamps), Filament `MaterialResource` with file uploads to `storage/app/public/materials/{class_id}/`, and a public materials list route. This builds naturally on the `teacher-class-management` capability since materials are per-class.
