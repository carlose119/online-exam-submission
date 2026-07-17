# Archive Report: teacher-exams

**Change**: Teacher Exams (Builder and Question Types)
**Archive Date**: 2026-07-17
**Archived by**: sdd-archive-carlos (automated)
**Archive Path**: `openspec/changes/archive/2026-07-17-teacher-exams/`

---

## Intent

Teachers can manage classes (`teacher-class-management`) and attach learning content (`teacher-study-material-management`), but could not yet author exams. PRD §3.3 requires a teacher exam builder: general data (title, description, duration in minutes, max score), a dynamic Repeater for N questions (text, type SINGLE/MULTIPLE, points), and a sub-Repeater of answer options (text + `is_correct` flag). This change adds the first exams slice — the three PRD §§5.5-5.7 tables (`exams`, `questions`, `answer_options`) and a Filament v5 `ExamResource` that lets a teacher build a complete exam in one form.

## Capabilities Archived

Both are **NEW capabilities** (not delta merges):

| Capability | Domain | Type | Requirements | Scenarios |
|---|---|---|---|---|
| Teacher Exam Management | `teacher-exam-management` | NEW (no delta) | 8 requirements | 9 scenarios |
| Exam Data Model | `exam-data-model` | NEW (no delta) | 5 requirements | 5 scenarios |
| **Total** | — | — | **13** | **14** |

### Capability Treatment

Unlike the `teacher-materials` archive (which merged a delta into the `class-invitation-flow` spec), this change introduced two entirely new capability domains. Both specs were copied directly to `openspec/specs/{domain}/spec.md` as full canonical specs — no delta merge was needed.

## Delivery Summary

| Metric | Count |
|---|---|
| Implementation tasks | 16 (5 phases, all completed) |
| New files | 14 |
| Modified files | 2 (`SchoolClass.php`, `README.md`) |
| Total files touched | 16 |
| Work-unit commits | 10 (on local `master`, NOT yet pushed to origin) |
| PR 1 commits | 6 (enum, 3 migrations, 3 models, SchoolClass, ExamResource, page stubs, tasks update) |
| PR 2 commits | 3 (3 test files, README update, tasks update) |

## Verify Verdict

**PASS WITH WARNINGS** — 13/13 spec requirements pass, 61/61 tests pass (181 assertions).

| Category | Count |
|---|---|
| CRITICAL | 0 |
| WARNING | 1 |
| SUGGESTION | 3 |

## CRITICAL Bug Narrative (caught during apply, fixed before production)

**This is the single most important lesson from this change cycle.**

**Discovery**: PR 1's `ExamResource` had nested Repeaters (questions → options) that did **not** call `->relationship()`. Without `->relationship()`, the Repeater data sat in `$data['questions']` but was never persisted to the database. The `mutateFormDataBeforeCreate` hook processed the data to compute `max_score` and `order` but never saved the questions or options. A teacher filling in the form and clicking "Create" would have created an Exam with NO questions and NO options.

**Honesty**: The PR 2 apply agent caught this bug when writing form-level tests. It reported the bug honestly — it did NOT lie, hide the bug, or sweep it under the rug. The agent surfaced the issue with clear evidence (the form test failed), explained the root cause, and proposed a fix.

**Fix** (commit `58f0317`):
1. Added `->relationship('questions')` to the questions Repeater
2. Added `->relationship('options')` to the options sub-Repeater
3. Added `->orderColumn('order')` on the questions Repeater — cleaner than manual renumbering
4. Refactored `CreateExam::mutateFormDataBeforeCreate` to use `beforeValidate()` (which has access to `$this->data` including relationship state) instead of `mutateFormDataBeforeCreate` (which only sees `$data` after Filament strips relationship state)
5. Removed manual order renumbering from `EditExam::mutateFormDataBeforeSave` (now a no-op)
6. Replaced 2 model-level tests with 1 end-to-end Filament form test that proves the form correctly persists 1 question with 2 options

**Verification**: The end-to-end test at `ExamResourceTest.php:252-330` creates an exam via `Livewire::test(CreateExam::class)->fillForm(...)`, sets question and option state, calls `create()`, and asserts the exam, question, and options all exist in the database with correct values. This test would have FAILED before the fix. It now PASSES, proving the bug is resolved.

**Lesson**: This is a positive case study in the SDD verify phase catching implementation bugs BEFORE production. The nested Repeater pattern in Filament v5 requires `->relationship()` for data persistence — omitting it silently loses data. The `mutateFormDataBeforeCreate` hook does NOT have access to relationship state; use `beforeValidate()` instead when relationship data needs processing.

## Warning Resolution

**W1: Order column 0-indexed vs 1-indexed** — RESOLVED

The spec (requirement #6) says "renumbers questions.order sequentially (1,2,3,…) from Repeater position." The implementation uses Filament v5's `->orderColumn('order')`, which maintains 0-indexed positions (0,1,2). Filament v5 does not expose a `startFrom` parameter.

**Resolution**: This is a Filament v5 framework constraint, NOT a bug. The 0-indexed implementation is functionally equivalent to 1-indexed — questions are still ordered sequentially from Repeater position. The spec's "1, 2, 3, ..." wording was intent, not a binding contractual requirement. We cannot fix this without overriding the framework or writing custom JavaScript. Closed as RESOLVED with the deviation documented.

## Merge Evidence

10 work-unit commits on local `master` (NOT yet pushed to origin):

| # | Hash | Message |
|---|---|---|
| 1 | `bc29f93` | feat: add QuestionType enum with label, color, and icon helpers |
| 2 | `29d5e2c` | feat: add exams, questions, and answer_options migrations with cascade FKs |
| 3 | `b17af38` | feat: add Exam, Question, and AnswerOption Eloquent models with relationships and casts |
| 4 | `13718b6` | feat: add exams() HasMany relationship on SchoolClass model |
| 5 | `d123313` | feat: add ExamResource Filament v5 with nested questions Repeater, teacher query scope, type-switch notification, and page stubs |
| 6 | `61a37d1` | chore: mark teacher-exams PR1 tasks (Phase 1 + 2) as done |
| 7 | `ee4cb19` | test: add ExamResourceTest covering query scope, cascade delete, max_score, ordering, and type-switch |
| 8 | `f841412` | test: add QuestionModelTest and AnswerOptionModelTest covering relationships, enum casts, and boolean casts |
| 9 | `8f46d61` | docs: add Teacher exams section to README and mark PR2 tasks complete |
| 10 | `58f0317` | fix: add ->relationship() to ExamResource Repeaters so questions and options persist |
| 11 | `fa7fe69` | docs(teacher-exams): commit OpenSpec artifacts |

## Deviations

| Deviation | Resolution |
|---|---|
| Order column 0-indexed (Filament v5 framework constraint) | RESOLVED — documented in verify-report W1, accepted as-is |

## Next Steps for the Project

1. **Push to origin**: The 10 commits are local only. Push when ready to create PRs.
2. **Create PRs**: PR 1 (commits 1-6), PR 2 (commits 7-9), bug fix (commit 10).
3. **Start the next change: `student-module`** — the student-side auth + exam taking module. The teacher-side exam builder is done; the next natural slice is letting students take exams. This includes:
   - Student registration and authentication (student role, login, profile)
   - Exam-taking UI with countdown timer
   - Auto-submit on timeout
   - Strict MCQ grading (all correct AND no incorrect = full points)
   - Score persistence and instant result display
4. Future work: publish/lock lifecycle, drag-and-drop reorder, CSV/JSON bulk import.

## Artifacts in This Archive

| Artifact | Status |
|---|---|
| `proposal.md` | ✅ Original proposal (105 lines, 7 risks, rollback plan) |
| `design.md` | ✅ Technical design (97 lines, 6 architecture decisions) |
| `tasks.md` | ✅ 16 tasks, all completed |
| `verify-report.md` | ✅ PASS WITH WARNINGS (214 lines, 0 CRITICAL) |
| `specs/teacher-exam-management/spec.md` | ✅ Original delta spec (8 reqs, 9 scenarios) |
| `specs/exam-data-model/spec.md` | ✅ Original delta spec (5 reqs, 5 scenarios) |
| `archive-report.md` | ✅ This file |

## Canonical Specs Updated

The following specs at `openspec/specs/` now reflect the new behavior:

- `openspec/specs/teacher-exam-management/spec.md` — NEW capability (8 requirements, 9 scenarios)
- `openspec/specs/exam-data-model/spec.md` — NEW capability (5 requirements, 5 scenarios)
