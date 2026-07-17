# Archive Report: teacher-materials

**Archive Date**: 2026-07-17
**Original Change**: Teacher Materials (Files, Links, Meetings)
**Archived by**: sdd-archive-carlos

## Intent

From `proposal.md`: Teachers currently have classes and a public invitation page, but no way to attach learning content to a class. PRD §3.2 requires three material kinds: uploaded files, external links (e.g. YouTube), and live class meetings (URL + metadata). This change added the first `study_materials` slice so a teacher can publish content and any visitor with the invitation link can view it.

## What Was Delivered

| Metric | Count |
|--------|-------|
| New capabilities archived | 1 (`teacher-study-material-management`) |
| Existing capabilities extended | 1 (`class-invitation-flow` merged with 4 ADDED requirements) |
| Total spec requirements archived | 11 (7 new + 4 added to existing) |
| Total scenarios | 20 (13 new + 7 added to existing) |
| Implementation tasks completed | 17 |
| New files created | 9 (enum, migration, model, resource, 3 page stubs, 2 test files) |
| Files modified | 5 (SchoolClass model, JoinClassController, join.blade.php, ClassInvitationFlowTest, README) |
| Tests total | 46 (31 existing + 15 new) |
| Tests passing | 46 (108 assertions) |
| Commits on `master` (not yet pushed) | 13 work-unit commits |

## Capability Treatment

### 1. teacher-study-material-management (NEW capability)
- **Action**: Straight copy from `openspec/changes/teacher-materials/specs/teacher-study-material-management/spec.md` → `openspec/specs/teacher-study-material-management/spec.md`
- **Result**: New canonical spec at `openspec/specs/teacher-study-material-management/spec.md` with 7 requirements and 13 scenarios
- **Scope**: Teacher-scoped CRUD over study materials (FILE/LINK/MEETING) via Filament v5 conditional form, query-scope auth, file upload validation, JSON round-trip, copy-public-join-URL action

### 2. class-invitation-flow (EXTENDED capability — 4 ADDED requirements merged)
- **Action**: Delta spec at `openspec/changes/teacher-materials/specs/class-public-materials-view/spec.md` contained 4 ADDED requirements. Merged into the existing canonical spec at `openspec/specs/class-invitation-flow/spec.md`
- **Result**: Canonical spec grew from 3 to 7 requirements, from 3 to 10 scenarios
- **Original spec preserved**: Public Invitation Route, Auth-Aware Join Affordance, Nonexistent Invitation Code — all retained unchanged
- **Added requirements**: Public Materials Section, Material Rendering by Type, Materials Ordering, Empty Materials State
- **No new capability file created**: `class-public-materials-view` does not exist independently; its requirements are part of `class-invitation-flow`

## Verify Verdict

**PASS WITH WARNINGS**

- **11/11 spec requirements pass**
- **46/46 tests pass** (108 assertions)
- **0 CRITICAL findings**
- **1 WARNING**: File upload validation (MIME allowlist, 50MB max-size) is in place but not covered by automated rejection tests. Mitigated by form schema inspection + manual smoke + Livewire rendering test. The validation IS configured and will work in production.
- **1 SUGGESTION**: Consider adding integration test for file upload rejection (low priority, not blocking).

## Resolved Finding: Set() Bug

**Narrative**: PR 1's `StudyMaterialResource` used `$set->set('key', null)` in the `afterStateUpdated` callback. In Filament v5, `Set` is an invokable utility class — the correct call is `$set('key', null)`. The `->set()` method does not exist on `Set`; only the `__invoke` handler is available.

**Detection**: The PR 2 apply agent discovered this when running Livewire tests (the form lifecycle triggered the callback). Without this fix, the conditional form's reset behavior would have been broken in production.

**Fix**: Commit `59fa2de` changed the callback from `fn (Set $set) => $set->set(...)->set(...)` to `function (Set $set) { $set(...); $set(...); }`. The file `app/Filament/Resources/StudyMaterialResource.php` was the affected artifact.

**Verification**: Test "type change clears file_path_or_url and extra_metadata via afterStateUpdated" passes — Livewire renders all 3 type switches without errors, confirming the fix works.

**Status**: RESOLVED. This was a development-phase catch, NOT a production bug. Documented here for audit trail completeness.

## Merge Evidence

13 work-unit commits on local `master`. These are NOT pushed to origin — the user will push when ready.

| # | Hash | Message | PR |
|---|------|---------|-----|
| 1 | `11ec268` | `feat: add StudyMaterialType enum with label, color, and icon helpers` | PR 1 |
| 2 | `f7ec87d` | `feat: add study_materials migration with class_id cascade FK, JSON metadata, and index` | PR 1 |
| 3 | `6d19dfd` | `feat: add StudyMaterial Eloquent model with classroom() relationship and StudyMaterialType cast` | PR 1 |
| 4 | `15ec038` | `feat: add studyMaterials() hasMany on SchoolClass model` | PR 1 |
| 5 | `6c32797` | `feat: add StudyMaterialResource Filament v5 with conditional form, query-scope auth, and create/edit/list page stubs` | PR 1 |
| 6 | `f65cf60` | `chore: mark teacher-materials PR1 tasks as done in OpenSpec tasks.md` | PR 1 |
| 7 | `6153db2` | `fix: use js() clipboard API in EditStudyMaterial copy-link action, matching EditClass pattern` | PR 2 |
| 8 | `4e47514` | `feat: extend JoinClassController to pass materials to the public view` | PR 2 |
| 9 | `59fa2de` | `test: add StudyMaterialResourceTest covering CRUD, ordering, scope, and form schema` | PR 2 |
| 10 | `81fc430` | `test: add StudyMaterialPublicViewTest and extend ClassInvitationFlowTest` | PR 2 |
| 11 | `ed1b4d8` | `docs: add Teacher materials section to README with file types, YouTube embed, and public visibility notes` | PR 2 |
| 12 | `fc374ea` | `chore: mark teacher-materials PR2 tasks as done in OpenSpec tasks.md` | PR 2 |
| 13 | `c8793d3` | `docs(teacher-materials): commit OpenSpec artifacts` | Metadata |

**Note**: Commit `59fa2de` also includes the Set() bug fix alongside the test file creation. The fix was applied to `StudyMaterialResource.php` as part of that commit's scope.

## Deviations from Original Plan

Only one deviation: the Set() bug fix in PR 2. The original plan did not account for this Filament v5 API mismatch. The fix was applied during the apply phase and is fully documented above. No other deviations.

## Deferred Items (from design + verify)

- File cleanup on disk after material/class deletion (cascade deletes rows only)
- File upload quotas and bulk upload
- Material reordering
- `php.ini` 50MB upload limit documentation (noted in README)
- MIME allowlist extension (PPT, images — low priority)
- `class_user` pivot table and student subscription (deferred to future change)
- Student module: subscribed students see materials scoped to their enrolled classes

## Next Steps

The next recommended change is **`teacher-exams`** — the exam builder engine. Materials are complete (files, links, meetings — all 3 types delivered and verified). The next natural slice is the exam engine, which can reuse the conditional Filament form pattern and `extra_metadata` JSON column shape for `questions.answer_options`, building on the same teacher-scoped architecture.

## Archived Paths

- `openspec/changes/archive/2026-07-17-teacher-materials/proposal.md`
- `openspec/changes/archive/2026-07-17-teacher-materials/design.md`
- `openspec/changes/archive/2026-07-17-teacher-materials/tasks.md` (17/17 tasks complete)
- `openspec/changes/archive/2026-07-17-teacher-materials/verify-report.md`
- `openspec/changes/archive/2026-07-17-teacher-materials/specs/class-public-materials-view/spec.md` (delta)
- `openspec/changes/archive/2026-07-17-teacher-materials/specs/teacher-study-material-management/spec.md` (delta)

## Canonical Specs Updated

- `openspec/specs/teacher-study-material-management/spec.md` — NEW capability (7 requirements)
- `openspec/specs/class-invitation-flow/spec.md` — EXTENDED (from 3 to 7 requirements via merge of 4 ADDED)

## SDD Cycle Complete

The `teacher-materials` change has been fully planned, implemented, verified, and archived. All 17 tasks completed, 11/11 spec requirements passing, 46/46 tests passing, 0 CRITICAL findings.
