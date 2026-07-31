# Proposal: Reports (Per-Class PDF + Excel Exports, Sync + Queue)

## Intent (Why)

Changes 1–6 built classes, exams, subscriptions, and the exam engine that persists graded `student_attempts`/`student_answers`. TEACHERs and ADMINs have all the data but no way to **export** it. PRD §3.2 "Módulo de Reportes y Exportación" and §6 (NFR) require downloadable **PDF** and **Excel/CSV** reports of the evaluation plan and grades, citing `barryvdh/laravel-dompdf` and `maatwebsite/excel`. This change delivers the **first product slice = the per-class report** (drill-down to per-exam, drill-down to per-attempt), with PDF + Excel export, hybrid sync/queue generation, and role-based access control (TEACHER sees own classes, ADMIN sees all). No deferred concern is silently included.

## What Changes

### Composer dependencies (NFR §6)
- `composer require barryvdh/laravel-dompdf` (PDF generation) and `maatwebsite/excel` (Excel generation). Both widely-used, well-maintained; no security audit required.

### Filament resource + custom page
- `app/Filament/Resources/ClassReportResource.php` — table of classes. `getEloquentQuery()` scopes: TEACHER → `whereHas('teacher', fn $q => $q->where('users.id', Auth::id()))`; ADMIN → all. Columns: title, teacher.name, # exams, # students (via `subscribedClasses`), # attempts.
- `app/Filament/Resources/ClassReportResource/Pages/ClassReport.php` — custom page showing per-class stats + "Download PDF"/"Download Excel" actions, drill-down to per-exam stats, drill-down to per-attempt.

### Services (business logic + formatting, shared by sync + queue)
- `app/Services/ClassReportService.php` — `generate($classId): array` queries the data and computes stats (per-exam # attempts, average score `X / Y`, pass rate), returning a structured array.
- `app/Services/ReportFormatService.php` — `toPdf($data, $classId)` and `toExcel($data, $classId)` generate the file and return its path.

### Jobs (queue path, `database` driver already configured)
- `app/Jobs/GenerateClassReportPdf.php` and `app/Jobs/GenerateClassReportExcel.php` — call service + formatter, store file in `storage/app/reports/`, send a `Filament\Notifications\Notification` with a download link.

### Templates + export + config + storage
- `resources/views/reports/class-pdf.blade.php` — dompdf template (Blade + dompdf-compatible CSS).
- `app/Exports/ClassReportExcelExport.php` — extends `Maatwebsite\Excel\Concerns\FromCollection` (columns, headers, styling) for the per-exam variant.
- `config/reports.php` — `sync_threshold` (default 100 attempts), `pass_rate_threshold` (default 0.6).
- `storage/app/reports/` directory + `reports` disk in `config/filesystems.php`.

### Download behavior (hybrid)
- Sync (`< 100` attempts): controller calls service → formatter → `Storage::download()`.
- Queue (`>= 100`): controller dispatches job; job stores file + sends Filament notification with download link (no email — mailer not configured).

### Pass rate
- A student "passes" when `score_obtained >= pass_rate_threshold * exam.max_score` (default 60%). Per-exam pass rate = `(passing_attempts / total_attempts) * 100`.

### Tests (Pest)
- `tests/Feature/ClassReportTest.php` — sync generation, access control (teacher own / admin all), drill-down to per-exam, pass rate, PDF content (key strings), Excel content (columns + data).
- `tests/Feature/GenerateClassReportPdfJobTest.php` — queue generation, file storage, Filament notification.
- `tests/Feature/ClassReportServiceTest.php` — stats computation, pass rate, sort order in isolation.

### README
- New "Reports: PDF + Excel exports for exams" section after the "Exam engine" section: how to generate, sync vs queue, pass rate, access control, deferred items (per-student report, email notifications, scheduled reports, custom builder, charts).

## Capabilities

> This section is the CONTRACT between proposal and specs phases. Existing capability names are taken from `openspec/specs/`.

### New Capabilities
- `teacher-report-exports`: The per-class (drill-down to per-exam, drill-down to per-attempt) report generation, PDF + Excel export, hybrid sync/queue generation, role-based access control (TEACHER own classes / ADMIN all), pass-rate computation, and the `config/reports.php` thresholds.

### Modified Capabilities
- None. Reports are read-only over existing data; no existing spec-level requirement changes.

### Unchanged Capabilities (no spec-level behavior change)
- `teacher-class-management` — `SchoolClass` query read-only by the resource query scope.
- `teacher-exam-management`, `exam-data-model` — exam/question/option structure consumed read-only.
- `student-class-subscription` — `class_user` pivot counted for "# students".
- `exam-attempt-data`, `exam-grading` — `student_attempts`/`student_answers` and `score_obtained` queried read-only.
- `platform-scaffold` — Filament v5 panel and `database` queue reused; no structural change.

## Approach

The report is a **read-only projection** of existing data; no schema change. `ClassReportService` is the single business-logic entry point (stats + pass rate), reused by both the sync controller path and the queued jobs — keeping Filament actions thin and tests fast. The sync-vs-queue decision is a threshold check in the controller (`config('reports.sync_threshold')`, default 100) against `student_attempts` count; large reports go to the already-configured `database` queue (no new Redis). Access control is a Filament `getEloquentQuery()` scope keyed on `Auth::user()->role`, matching the existing `ClassResource`/`ExamResource` auth pattern. The PDF is a Blade template rendered by `laravel-dompdf`; the Excel is a `FromCollection` export rendered by `maatwebsite/excel`. Queued results surface via `Filament\Notifications\Notification` (in-panel download link) since no mailer is configured. Test style follows the existing Pest suite (`ExamTakingTest`, `ExamTimerTest`).

## Impact

| Area | Impact | Description |
|------|--------|-------------|
| `composer.json`, `composer.lock` | Modified | Add `barryvdh/laravel-dompdf`, `maatwebsite/excel`. |
| `app/Filament/Resources/ClassReportResource.php` | New | Resource + role-based query scope + download actions. |
| `app/Filament/Resources/ClassReportResource/Pages/ClassReport.php` | New | Custom per-class + per-exam + per-attempt drill-down page. |
| `app/Services/{ClassReportService,ReportFormatService}.php` | New | Business logic + formatting. |
| `app/Jobs/{GenerateClassReportPdf,GenerateClassReportExcel}.php` | New | Queue path jobs. |
| `resources/views/reports/class-pdf.blade.php` | New | dompdf template. |
| `app/Exports/ClassReportExcelExport.php` | New | `FromCollection` Excel export. |
| `config/reports.php`, `config/filesystems.php` | New / Modified | Thresholds + `reports` disk. |
| `storage/app/reports/` | New | Generated files (gitignored). |
| `tests/Feature/{ClassReport,GenerateClassReportPdfJob,ClassReportService}Test.php` | New | Pest coverage. |
| `README.md` | Modified | New Reports section. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| **2 new composer deps** (`laravel-dompdf`, `maatwebsite/excel`) bring transitive packages; version conflicts with Filament v5 / Livewire v4. | Low | Both widely-used and Laravel-13-compatible; `composer require` will surface conflicts at install. Pin to latest stable; no security audit needed. |
| **Queue driver = `database`** is already configured but is slower than Redis for very large reports; large classes could saturate the `jobs` table. | Med | Threshold (100) keeps large reports off the sync path; recommend Redis in production (deferred). `GenerateClassReportPdfJobTest` covers the queue path. |
| **No mailer configured** — queued reports cannot email the user a link; they only get an in-panel Filament notification. A user who closed the panel misses the notice. | Med | Filament notification is the chosen channel (matches cached preflight). Email notifications explicitly **deferred**. README documents the limitation. |
| **Hardcoded sync threshold (100 attempts)** may be wrong for this environment — too high (slow sync, PHP `max_execution_time`) or too low (unnecessary queue churn). | Med | Configurable via `config/reports.php` `sync_threshold`; tunable per environment. |
| **Pass rate threshold (60%)** is an assumption not grounded in PRD (PRD does not state a pass mark). | Med | Configurable via `config('reports.pass_rate_threshold')`; documented in README as an assumption inviting confirmation (see Question Round). |
| **Per-student report deferred** — a teacher wanting an individual student's progress gets only the per-exam drill-down, not a dedicated student-progress report. | Low | Out of scope; per-exam drill-down shows each student's attempts/score. Listed in Future Capabilities. |
| **dompdf CSS subset** — Blade template using unsupported CSS renders incorrectly (no flexbox/grid). | Low | Use dompdf-compatible CSS (tables, inline styles); test asserts key strings render. |
| **Review budget** — 2 resources, 2 services, 2 jobs, template, export, config, 3 test files likely exceed 400 authored lines. | Med | `sdd-tasks` will forecast and recommend chained PRs per the `ask-always` strategy (e.g., deps+service, Filament resource, jobs+template, tests+README). |

## Rollback Plan

- `composer remove barryvdh/laravel-dompdf maatwebsite/excel` (reverts deps; no migration was added).
- Delete `app/Filament/Resources/ClassReportResource.php` and its `Pages/` directory.
- Delete `app/Services/ClassReportService.php`, `app/Services/ReportFormatService.php`, `app/Jobs/GenerateClassReportPdf.php`, `app/Jobs/GenerateClassReportExcel.php`, `app/Exports/ClassReportExcelExport.php`.
- Delete `resources/views/reports/`.
- Revert `config/reports.php` (delete) and the `reports` disk entry in `config/filesystems.php`.
- Delete generated files in `storage/app/reports/` (optional; gitignored).
- Delete the three Pest test files and revert the README Reports section.
- No database rollback needed — this change adds no migration. No class/exam/subscription/attempt/answer data is touched (read-only).

## Dependencies

- `teacher-class-management` — `SchoolClass` + `teacher()` relationship queried by the resource scope.
- `teacher-exam-management` / `exam-data-model` — `exams`/`questions`/`answer_options` consumed read-only.
- `student-class-subscription` — `class_user` pivot + `User::subscribedClasses()` for student counts.
- `exam-attempt-data` — `student_attempts` (`score_obtained`, `finished_at`) queried for stats.
- `exam-grading` — `score_obtained` semantics (sum of correctly-answered `question.points`) define the pass-rate numerator.
- `platform-scaffold` — Filament v5 panel, `database` queue driver, Pest runtime; no new infrastructure.

## Future Capabilities Enabled

- **Per-student report** — a dedicated student-progress report (currently only the per-exam drill-down shows per-student scores).
- **Email notifications** for queued reports — needs a configured mailer (no `MAIL_MAILER` today).
- **Scheduled / recurring reports** — "send me a weekly PDF of my classes' performance".
- **Custom report builder UI** — teacher picks columns/filters.
- **Charts / graphs** in reports — tables only for now.
- **Redis queue driver** — for production-scale large reports (currently `database`).

## Success Criteria

- [ ] TEACHER sees only their own classes in `ClassReportResource`; ADMIN sees all classes.
- [ ] The custom page shows per-class stats + drill-down to per-exam stats + drill-down to per-attempt.
- [ ] Pass rate per exam = `(passing_attempts / total_attempts) * 100` where pass = `score_obtained >= 0.6 * max_score`.
- [ ] "Download PDF" generates a PDF containing class title, exam list with stats, and overall stats; "Download Excel" generates the equivalent spreadsheet.
- [ ] Reports `< 100` attempts generate synchronously via `Storage::download`; reports `>= 100` dispatch a job to the `database` queue.
- [ ] Queued jobs store the file in `storage/app/reports/` and send a `Filament\Notifications\Notification` with a download link.
- [ ] `config/reports.php` exposes `sync_threshold` (100) and `pass_rate_threshold` (0.6), both overridable.
- [ ] Pest tests pass: `ClassReportTest`, `GenerateClassReportPdfJobTest`, `ClassReportServiceTest`.
- [ ] README has a Reports section documenting usage, sync vs queue, pass rate, access control, and deferred items.

## Proposal Question Round (interactive mode)

Resolved product decisions are encoded as assumptions above (first slice = per-class + drill-down; hybrid sync/queue; TEACHER-own / ADMIN-all access; pass mark 60%). Open technical questions are resolved with recommendations already chosen (see Result Contract `open_questions_resolved`). One product assumption is **not** grounded in the PRD and benefits from confirmation:

1. **Pass mark**: PRD §3.2/§6 do not state a pass threshold. Recommendation: 60% (`config('reports.pass_rate_threshold')`), documented and overridable. Does the reviewer confirm 60%, or want a different default / per-exam override (deferred)?

Candidate second-round questions (only if reviewer wants more): (2) should the per-exam drill-down also be independently downloadable as a per-exam PDF/Excel, or always bundled in the per-class report? (recommend: independently downloadable, reusing the service). (3) should queued-report files be auto-cleaned after N days? (recommend: defer; manual cleanup for now).

## Open Questions Resolved

- Sync vs queue threshold: 100 attempts, configurable via `config/reports.php` (`sync_threshold`, default 100).
- Queue driver: `database` (already configured; no new Redis).
- PDF template: Blade at `resources/views/reports/class-pdf.blade.php` rendered by `barryvdh/laravel-dompdf`.
- Excel export: `ClassReportExcelExport` at `app/Exports/` extending `Maatwebsite\Excel\Concerns\FromCollection`.
- Report storage: local disk at `storage/app/reports/` (no S3); `reports` disk in `config/filesystems.php`.
- Download mechanism: sync = `Storage::download`; queue = Filament notification with download link.
- Filament resource: `ClassReportResource` with role-based `getEloquentQuery()` scope (TEACHER own, ADMIN all).
- Pass rate threshold: 60% via `config('reports.pass_rate_threshold')`, default 0.6.
- Tests: 3 new Pest files (`ClassReportTest`, `GenerateClassReportPdfJobTest`, `ClassReportServiceTest`).