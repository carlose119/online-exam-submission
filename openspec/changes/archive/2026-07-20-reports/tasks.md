# Tasks: Reports (Per-Class PDF + Excel Exports)

## Review Workload Forecast

Estimated changed lines: 700–900. 18 files (14 new + 4 modified). No generated code.

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main (PR #1 → main ✅, then PR #2 → main)
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | PR | Focused test | Runtime harness | Rollback |
|------|------|----|-------------|-----------------|----------|
| 1 | Deps, configs, services, jobs, exports, template | 1 | `vendor/bin/pest tests/Feature/ClassReportServiceTest.php` | `tinker: app(ClassReportService::class)->generate(1)` | Remove deps, delete services/jobs/exports/config/template/reports-disk |
| 2 | Filament resource, page, controller, route, tests, README | 2 | `vendor/bin/pest tests/Feature/ClassReportTest.php tests/Feature/GenerateClassReportPdfJobTest.php` | Login → View Report → Download PDF | Delete resource+pages, controller, revert route, delete tests, revert README |

## Phase 1: Dependencies & Configuration (PR 1)

- [x] 1.1 `composer require barryvdh/laravel-dompdf maatwebsite/excel`. Verify: `composer show | Select-String 'laravel-dompdf|maatwebsite'`.
- [x] 1.2 Create `config/reports.php`: `sync_threshold`=100, `pass_rate_threshold`=0.6. Verify: `config('reports.sync_threshold')` returns 100.
- [x] 1.3 Add `reports` disk to `config/filesystems.php`: driver `local`, root `storage_path('app/reports')`, and create `storage/app/reports/.gitkeep`. Verify: config resolves + dir exists.

## Phase 2: Service Layer (PR 1)

- [x] 2.1 Create `app/Services/ClassReportService.php`: `generate(SchoolClass):array` — eager-loads exams.attempts, computes per-exam avg/pass-rate/median, overall stats. Verify: `app()->make()` resolves.
- [x] 2.2 Create `app/Exports/ClassReportExcelExport.php`: `FromCollection,WithHeadings,WithTitle,WithStyles`. Columns: Exam Title, Attempts, Avg Score, Pass Rate. Verify: `class_exists()`.
- [x] 2.3 Create `app/Services/ReportFormatService.php`: `toPdf()` (Blade→dompdf), `toExcel()` (collection→Excel). Both store on `reports` disk, return path. Verify: `app()->make()` resolves.
- [x] 2.4 Create `resources/views/reports/class-pdf.blade.php`: dompdf table — class title h1, teacher, per-exam stats, overall. Verify: file exists.

## Phase 3: Queue Jobs (PR 1)

- [x] 3.1 Create `app/Jobs/GenerateClassReportPdf.php`: `ShouldQueue`, `__construct(int,int)`, `handle()`→service→format→notification with download action. Verify: implements `ShouldQueue`.
- [x] 3.2 Create `app/Jobs/GenerateClassReportExcel.php`: same pattern calling `toExcel()`. Verify: implements `ShouldQueue`.

## Phase 4: Filament Resource & Page (PR 2)

- [x] 4.1 Create `app/Filament/Resources/ClassReportResource.php`: `$model=SchoolClass`, TEACHER→own/ADMIN→all scope; table: title,teacher,counts; "View Report" action. Verify: route shows via `php artisan route:list`.
- [x] 4.2 Create `app/Filament/Resources/ClassReportResource/Pages/ClassReport.php`: `Page+InteractsWithRecord`, header actions gating sync vs queue dispatch. Verify: class resolves.
- [x] 4.3 Create class-report page view: class title, teacher, per-exam drill-down table, overall stats footer. Verify: file exists.

## Phase 5: Download Route (PR 2)

- [x] 5.1 Create `app/Http/Controllers/ReportDownloadController.php`: `download($filename)` with `basename()` path-traversal guard, `Storage::disk('reports')->download()`. Verify: class exists.
- [x] 5.2 Add report download route to `routes/web.php`: `Route::get('/admin/reports/download/{filename}',[...])->middleware(['auth','role:admin,teacher'])`. Verify: route listed via `php artisan route:list`.

## Phase 6: Tests (PR 2)

- [x] 6.1 Create `tests/Feature/ClassReportServiceTest.php`: `RefreshDatabase`, avg/pass-rate/median, 100% pass, 0% pass, empty class, sort. Covers service-data, pass-rate-at-60%, arithmetic-correctness scenarios. Verify: `vendor/bin/pest` green.
- [x] 6.2 Create `tests/Feature/GenerateClassReportPdfJobTest.php`: `Queue::fake()`,`Storage::fake()`, dispatch+storage+notification assertions. Covers job spec scenarios. Verify: `vendor/bin/pest` green.
- [x] 6.3 Create `tests/Feature/ClassReportTest.php`: `actingAs()` teacher/admin/student, sync PDF/Excel, threshold, cross-teacher 404, download auth. Covers teacher-class-report spec (15 scenarios). Verify: `vendor/bin/pest` green.

## Phase 7: Documentation (PR 2)

- [x] 7.1 Update `README.md`: add Reports section after Exam Engine — usage, sync/queue, pass rate, access control, deferred items. Verify: `Select-String -Path README.md -Pattern 'Reports'`.
