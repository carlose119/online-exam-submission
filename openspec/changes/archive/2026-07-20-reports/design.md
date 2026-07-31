# Design: Reports (Per-Class PDF + Excel Exports, Sync + Queue)

## Technical Approach

Read-only projection over existing `classes`, `exams`, `student_attempts`, `student_answers` tables — no schema change. Two services (`ClassReportService` for business logic computing stats/pass-rate, `ReportFormatService` for PDF/Excel file generation) are reused by sync and queue paths. A sync-threshold check (`config('reports.sync_threshold')`, default 100 total attempts) gates between immediate `Storage::download()` and queued `database`-driver jobs. `ClassReportResource` Filament resource scoped by role (`Auth::user()->role`) mirrors existing `ClassResource`/`ExamResource` auth patterns.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|----------|--------|-------------|-----------|
| Service split | `ClassReportService` (data + stats) + `ReportFormatService` (files) | Monolithic service | Test business logic without PDF/Excel deps; reuse by sync + queue paths |
| Resource query scope | `getEloquentQuery()` with `when(Auth::user()->role === 'TEACHER', fn => whereHas('teacher'))` | TEACHER-only (like `ClassResource`) | ADMINs must see cross-teacher reports per spec requirement TCH-R001 |
| Job serialization | `int $classId, int $userId` in constructor | Serialize `SchoolClass`/`User` models | Eloquent models serialize unpredictably in queue payloads; resolve in `handle()` |
| Custom page rendering | Filament `Page` with `InteractsWithRecord` + Blade view | Infolist widgets | Drill-down (class→exam→attempt) needs custom nested-table layout |
| Download route | Standard `web.php` route with `auth` + `role:admin,teacher` | Panel route closure in `AdminPanelProvider` | Matches existing `routes/web.php` pattern; `CheckRole` middleware already registered |
| PDF library | `barryvdh/laravel-dompdf` (wraps dompdf) | `mpdf/mpdf`, `wkhtmltopdf` | PRD §6 specifies it; dompdf is pure-PHP (no external binary); CSS subset documented |
| Pass rate formula | `passing_attempts / total_attempts * 100` where pass = `score >= threshold * max_score` | Weighted by points, or per-question granularity | Simpler; threshold configurable; per the spec |

## Data Flow

```
TEACHER/ADMIN → ClassReportResource (table, role-scoped)
    → click "View Report" on a row
    → ClassReport custom page (class stats + exam table + drill-down)
        → "Download PDF" action
            → totalAttempts < config('reports.sync_threshold')?
              YES → ClassReportService::generate($class) → array
                  → ReportFormatService::toPdf($data, $class) → file path
                  → Storage::disk('reports')->download($path)
              NO  → dispatch GenerateClassReportPdf($classId, Auth::id())
                  → job handle(): service + formatter → store → Filament Notification
                      → user clicks notification link
                      → GET /admin/reports/download/{filename}
                      → ReportDownloadController → Storage::download()
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `composer.json` | Modify | `composer require barryvdh/laravel-dompdf maatwebsite/excel` adds 2 deps + auto-discovered SPs |
| `config/filesystems.php` | Modify | Add `'reports' => ['driver' => 'local', 'root' => storage_path('app/reports'), 'visibility' => 'private']` |
| `config/reports.php` | Create | `sync_threshold` (100), `pass_rate_threshold` (0.6), `storage_disk` ('reports'), `storage_path` ('reports') — all env-overridable |
| `routes/web.php` | Modify | Add `Route::get('/admin/reports/download/{filename}', [ReportDownloadController::class, 'download'])->name('reports.download')->middleware(['auth', 'role:admin,teacher'])` |
| `app/Services/ClassReportService.php` | Create | `generate(SchoolClass $class): array` — eager-loads exams.attempts.student, computes per-exam stats (count, avg `score_obtained` / `max_score`, pass rate, median) and overall stats |
| `app/Services/ReportFormatService.php` | Create | `toPdf(array $data, SchoolClass $class): string` (Blade→dompdf→`reports` disk), `toExcel(array $data, SchoolClass $class): string` (collection→`reports` disk) |
| `app/Jobs/GenerateClassReportPdf.php` | Create | `ShouldQueue`; `__construct(int $classId, int $userId)`; `handle(ClassReportService, ReportFormatService)` → stores file → `Notification::make()->success()->actions([Action::make('download')->url(route('reports.download', ['filename' => basename($path)]))])->sendToDatabase($user)` |
| `app/Jobs/GenerateClassReportExcel.php` | Create | Same pattern, calls `toExcel` + "Download Excel" notification |
| `app/Filament/Resources/ClassReportResource.php` | Create | `$model = SchoolClass::class`; `getEloquentQuery()`: `parent::getEloquentQuery()->when(Auth::user()->role === 'TEACHER', fn ($q) => $q->where('teacher_id', Auth::id()))->withCount(['exams', 'students', 'exams as attempts_count' => fn ($q) => $q->join('student_attempts', ...)])`; table columns: title, `teacher.name`, `exams_count`, `students_count`, `attempts_count`; row action: `Action::make('viewReport')->url(fn ($r) => static::getUrl('report', ['record' => $r]))` |
| `app/Filament/Resources/ClassReportResource/Pages/ClassReport.php` | Create | `Page` + `InteractsWithRecord`; `mount($record)` resolves `SchoolClass`; header actions `downloadPdfAction()` and `downloadExcelAction()` each call `ClassReportService` + threshold-gate sync vs dispatch; view receives `$this->record` + stats from service |
| `resources/views/filament/resources/class-report-resource/pages/class-report.blade.php` | Create | Renders class title, teacher name, description; per-exam table (columns: title, max_score, # attempts, avg "X / Y", pass rate %, median); each exam row expandable to student list (name, score, finished_at); overall stats footer |
| `resources/views/reports/class-pdf.blade.php` | Create | dompdf template: `<table>` layout (no flexbox/grid), class title h1, teacher name, per-exam table with stats, overall stats. Receives `$data` array |
| `app/Exports/ClassReportExcelExport.php` | Create | `FromCollection, WithHeadings, WithTitle, WithStyles`; headings: ["Exam Title", "Attempts", "Avg Score", "Pass Rate"]; each row from `$data['exams']`; sheet title = class title (sanitized ≤ 31 chars) |
| `app/Http/Controllers/ReportDownloadController.php` | Create | `download(string $filename)`: `abort_unless($filename === basename($filename), 400)` (path-traversal guard); `Storage::disk('reports')->download($filename)` |
| `tests/Feature/ClassReportTest.php` | Create | Sync PDF/Excel for small class, teacher sees own/admin sees all, cross-teacher 404, drill-down stats match seeded data, pass rate at 60% threshold, PDF/Excel assertSee key strings |
| `tests/Feature/GenerateClassReportPdfJobTest.php` | Create | `Queue::fake()` → `Bus::assertDispatched(GenerateClassReportPdf::class)`, `Storage::fake('reports')` → assert file stored, notification sent to DB |
| `tests/Feature/ClassReportServiceTest.php` | Create | Stats: avg, pass rate, median correct; all-pass (100%), all-fail (0%); empty class (no exams); sort order (exams newest-first) |
| `README.md` | Modify | Add "Reports" section after "Exam Engine": how to generate, sync vs queue threshold, pass rate config, access control, deferred items |

## Interfaces / Contracts

**ClassReportService::generate() returns:**
```php
[
    'class' => ['id', 'title', 'description'],
    'teacher' => ['name'],
    'exams' => [[
        'exam' => ['id', 'title', 'max_score', 'duration_minutes'],
        'attempts' => [['student_name', 'score_obtained', 'finished_at'], ...],
        'stats' => ['attempts_count', 'avg_score', 'pass_rate', 'median'],
    ]],
    'overall_stats' => ['total_attempts', 'avg_score', 'pass_rate'],
]
```
Pass determination: `score_obtained >= config('reports.pass_rate_threshold', 0.6) * exam.max_score`.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Service | Stats arithmetic, pass rate at thresholds | `RefreshDatabase` + seeded attempts; assert exact float values |
| Queue | Job dispatch + file storage + notification | `Queue::fake()`, `Storage::fake('reports')`, `DatabaseNotification` assertions |
| Feature | Access control (TEACHER/ADMIN/STUDENT), sync download, PDF/Excel content strings | `actingAs($teacher)` / `actingAs($admin)`; `assertSee()` on file content |
| Feature | Download route: auth gate, filename validation | `actingAs()`, assert 200/403/400 for path-traversal attempts |

Pest v4.7.5 + `pest-plugin-laravel`. `RefreshDatabase` via `tests/Pest.php`. SQLite `:memory:` (per `phpunit.xml`). Tests written AFTER implementation (not TDD).

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. The download route validates filenames via `basename()` for path-traversal prevention; this is standard input sanitization, not a threat-matrix boundary.

## Migration / Rollout

No database migration. `composer require` installs 2 packages (auto-discovered service providers: `Barryvdh\DomPDF\ServiceProvider`, `Maatwebsite\Excel\ExcelServiceProvider`). No `vendor:publish` needed (config values in `config/reports.php`, not package configs). Rollback: `composer remove barryvdh/laravel-dompdf maatwebsite/excel`, delete all new files, revert `routes/web.php` additions and `config/filesystems.php` disk entry.

## Open Questions

None. All technical decisions resolved in proposal. Pass-rate threshold (60%) configurable via `config/reports.php` and documented as assumption per Question Round.
