# report-generation-infrastructure Specification

## Purpose

Shared infrastructure for generating class reports: `ClassReportService` (business logic computing stats and pass rates), `ReportFormatService` (PDF and Excel file formatting), `GenerateClassReportPdf` and `GenerateClassReportExcel` queue jobs, Blade PDF template, `ClassReportExcelExport` class, `config/reports.php` thresholds, and `storage/app/reports/` directory. Consumed by `teacher-class-report` but independently specifiable and testable.

## Requirements

### Requirement: ClassReportService Data Generation

`ClassReportService::generate($classId)` MUST return a structured array containing class metadata (title, teacher name, description) and per-exam drill-down data. For each exam it MUST compute: number of attempts, average score, pass rate per `config('reports.pass_rate_threshold')`, median score, and per-attempt details (student name, score, finished_at). It MUST NOT depend on PDF or Excel libraries and MUST be testable in isolation.

#### Scenario: Service returns structured data for class with exams

- GIVEN class ID 1 with Exam A (3 attempts) and Exam B (2 attempts)
- WHEN `ClassReportService::generate(1)` called
- THEN returned array keys include `class`, `teacher`, `exams`; each exam entry has `attempts`, `avg_score`, `pass_rate`, `median`, and `students`

#### Scenario: Service test verifies business logic without PDF/Excel

- GIVEN `ClassReportServiceTest` with database or mocked exam/attempt data
- WHEN `generate()` called and assertions run
- THEN stats (avg, pass rate, median, count) are numerically correct without generating any output file

### Requirement: ReportFormatService PDF Generation

`ReportFormatService::toPdf($data, $classId)` MUST render `resources/views/reports/class-pdf.blade.php` via `barryvdh/laravel-dompdf`, store the PDF in `storage/app/reports/` using the `reports` disk, and return the absolute file path. The rendered PDF MUST contain the class title, teacher name, and per-exam stats as readable text strings.

#### Scenario: PDF generated and stored with expected content

- GIVEN structured report data for class ID 1 with title "Math 101" and teacher "Alice"
- WHEN `toPdf($data, 1)` called
- THEN file exists at path matching `storage/app/reports/class-1-*.pdf`
- AND PDF text content includes "Math 101" and "Alice"

### Requirement: ReportFormatService Excel Generation

`ReportFormatService::toExcel($data, $classId)` MUST use `maatwebsite/excel` via `ClassReportExcelExport` (extending `FromCollection`), store the file in `storage/app/reports/` using the `reports` disk, and return the absolute file path. The Excel file MUST contain one header row and one data row per exam with columns: title, number of attempts, average score, and pass rate.

#### Scenario: Excel generated with correct row structure

- GIVEN report data with 2 exams: "Quiz 1" (3 attempts, avg 12.00, pass 33.33%) and "Quiz 2" (2 attempts, avg 6.00, pass 50%)
- WHEN `toExcel($data, 1)` called
- THEN file stored at `storage/app/reports/class-1-*.xlsx`
- AND reading via `Excel::toArray()` returns header row + 2 data rows with matching values

### Requirement: GenerateClassReportPdf Queue Job

`GenerateClassReportPdf` job (constructor: `$classId, $userId`) MUST call `ClassReportService::generate($classId)` then `ReportFormatService::toPdf(...)`. After storing the file, it MUST send a `Filament\Notifications\Notification` to the target user with a "Download PDF" action linking to the stored file.

#### Scenario: Job generates PDF and notifies user

- GIVEN class ID 1 with 150 attempts, user ID 5
- WHEN `GenerateClassReportPdf` dispatched and processed
- THEN PDF stored in `storage/app/reports/`
- AND `Filament\Notifications\Notification` sent to user 5 with download action

#### Scenario: Job test verifies dispatch with Queue::fake

- GIVEN `GenerateClassReportPdfJobTest`
- WHEN test dispatches the job and uses `Queue::fake()`
- THEN `Queue::assertPushed(GenerateClassReportPdf::class)` succeeds

### Requirement: GenerateClassReportExcel Queue Job

`GenerateClassReportExcel` job (constructor: `$classId, $userId`) MUST call `ClassReportService::generate($classId)` then `ReportFormatService::toExcel(...)`, store the file, and send a `Filament\Notifications\Notification` to the target user with a "Download Excel" action.

#### Scenario: Job generates Excel and notifies user

- GIVEN class ID 1 with 150 attempts, user ID 5
- WHEN `GenerateClassReportExcel` dispatched and processed
- THEN Excel stored in `storage/app/reports/`
- AND Filament notification sent to user 5 with download action

### Requirement: Database Queue Driver

Both `GenerateClassReportPdf` and `GenerateClassReportExcel` jobs MUST use the existing `database` queue connection. No Redis or additional queue infrastructure is required.

#### Scenario: Jobs insert into jobs table

- GIVEN `QUEUE_CONNECTION=database` in environment
- WHEN `GenerateClassReportPdf` dispatched
- THEN a row inserted into `jobs` table; processed by `php artisan queue:work`

### Requirement: Configuration File

`config/reports.php` MUST publish two values: `sync_threshold` (integer, default 100 — maximum attempts for synchronous generation) and `pass_rate_threshold` (float, default 0.6 — multiplier against exam.max_score to determine pass/fail). Both MUST be overridable per environment.

#### Scenario: Default config values resolve

- GIVEN `config/reports.php` published
- WHEN `config('reports.sync_threshold')` and `config('reports.pass_rate_threshold')` called
- THEN values 100 and 0.6 returned respectively

### Requirement: Reports Storage Disk

`config/filesystems.php` MUST define a `reports` disk with driver `local` and root `storage_path('app/reports')`. The `storage/app/reports/` directory MUST be auto-created if it does not exist when files are written.

#### Scenario: Reports disk stores file

- GIVEN `reports` disk configured and directory absent
- WHEN `Storage::disk('reports')->put('test.txt', 'hello')` called
- THEN directory created and file stored at `storage/app/reports/test.txt`

### Requirement: Pass Rate Arithmetic Correctness

`ClassReportService` MUST compute pass rate as `(count of passing attempts / count of total attempts) * 100`. It MUST NOT round or truncate intermediate values prematurely, preserving at least two decimal places in the final percentage.

#### Scenario: Verified pass rate calculation

- GIVEN exam max_score=20, threshold=0.6 → passing threshold=12; 5 attempts: [18, 14, 12, 8, 5]
- WHEN `ClassReportService::generate()` computes pass rate
- THEN 3 passing; pass rate = 60.00% (3/5 * 100)
