# teacher-class-report Specification

## Purpose

Read-only Filament report over existing exam/attempt data. `ClassReportResource` at `/admin` scoped by role (TEACHER sees own classes via `teacher_id = Auth::id()`, ADMIN sees all). Custom `ClassReport` page with per-class stats, per-exam drill-down, per-attempt detail. PDF and Excel export: sync for `< 100` attempts, queued for `>= 100`. Pass rate computed as `score_obtained >= 0.6 * exam.max_score`, configurable via `config/reports.php`.

## Requirements

### Requirement: Role-Based Class Report Access

`ClassReportResource::getEloquentQuery()` MUST scope: TEACHER → `whereHas('teacher', fn $q => $q->where('users.id', Auth::id()))`; ADMIN → all classes. Non-ADMIN, non-TEACHER users MUST be denied via the existing `/admin` `CheckRole:ADMIN,TEACHER` middleware. Unauthenticated requests MUST redirect to Filament login.

#### Scenario: Teacher sees own classes only

- GIVEN Teacher A with 2 classes and Teacher B with 1 class
- WHEN Teacher A accesses `/admin/class-reports`
- THEN only Teacher A's 2 classes appear in the table

#### Scenario: Admin sees all classes

- GIVEN Admin authenticated; Teacher A has 2 classes, Teacher B has 1 class
- WHEN Admin accesses `/admin/class-reports`
- THEN all 3 classes appear

#### Scenario: Cross-teacher access returns 404

- GIVEN Teacher A authenticated; Teacher B's class exists
- WHEN Teacher A requests the report page for Teacher B's class
- THEN HTTP 404 returned

#### Scenario: Empty teacher sees empty list

- GIVEN Teacher with zero classes authenticated
- WHEN accessing `/admin/class-reports`
- THEN empty table displayed

#### Scenario: Guest redirected to login

- GIVEN no session
- WHEN accessing `/admin/class-reports`
- THEN redirected to Filament `/admin/login`

#### Scenario: Student denied access

- GIVEN authenticated STUDENT
- WHEN accessing `/admin/class-reports`
- THEN access denied via `CheckRole:ADMIN,TEACHER` middleware

### Requirement: Per-Class Stats View

The custom `ClassReport` page MUST display: class title, teacher name, and description. For each exam in the class, it MUST show average score as "X / Y", pass rate percentage, and number of attempts. Overall aggregate stats MUST include: total attempts across all exams, overall average score, and overall pass rate.

#### Scenario: Class with exams shows per-exam and overall stats

- GIVEN class "Math 101" with Exam A (max=20, 3 attempts scores 15/12/9 → avg 12.00, 1 passing) and Exam B (max=10, 2 attempts scores 8/4 → avg 6.00, 1 passing)
- WHEN class report renders
- THEN Exam A shows avg "12.00 / 20", pass rate "33.33%", 3 attempts
- AND Exam B shows avg "6.00 / 10", pass rate "50%", 2 attempts
- AND overall: 5 total attempts, avg "9.60 / —", pass rate "40.00%"

### Requirement: Per-Exam Drill-Down

The per-exam drill-down MUST show: exam title, max_score, duration_minutes, and a student list with name, score formatted as "X / Y", and finished_at timestamp. Per-exam aggregate stats MUST include: number of attempts, average score, pass rate, and median score.

#### Scenario: Drill-down shows student list and computed stats

- GIVEN "Quiz 1" (max=20) with attempts: Alice (15/20, finished '2026-01-01'), Bob (10/20, '2026-01-02'), Carol (5/20, '2026-01-03')
- WHEN drill-down to "Quiz 1"
- THEN students listed: Alice "15 / 20" 2026-01-01, Bob "10 / 20" 2026-01-02, Carol "5 / 20" 2026-01-03
- AND stats: 3 attempts, avg 10.00/20, pass rate 33.33%, median 10.00

### Requirement: Per-Attempt Detail

The system MUST display for a single attempt: student name, score as "X / Y" (score_obtained / exam.max_score), and finished_at timestamp.

#### Scenario: Per-attempt shows student result

- GIVEN Alice's attempt for "Quiz 1": score_obtained=15, exam.max_score=20, finished_at="2026-01-01 14:30:00"
- WHEN per-attempt detail renders
- THEN "Alice", "15 / 20", and "2026-01-01 14:30:00" are displayed

### Requirement: Sync Report Download

When total attempts in the class is strictly less than `config('reports.sync_threshold')` (default 100), "Download PDF" and "Download Excel" actions MUST generate the file synchronously via `ClassReportService` + `ReportFormatService` and return the file immediately in the HTTP response. The sync path MUST complete faster than dispatching a queue job for small reports.

#### Scenario: Sync PDF for small class

- GIVEN class with 5 total attempts (< threshold 100)
- WHEN teacher clicks "Download PDF"
- THEN PDF file generated and returned immediately; response completes without queue dispatch

#### Scenario: Sync Excel for small class

- GIVEN class with 5 total attempts (< threshold 100)
- WHEN teacher clicks "Download Excel"
- THEN Excel file generated and returned immediately

### Requirement: Queue Report Download

When total attempts is greater than or equal to `config('reports.sync_threshold')`, "Download PDF" MUST dispatch `GenerateClassReportPdf` job and "Download Excel" MUST dispatch `GenerateClassReportExcel` job. Both MUST use the `database` queue driver. Each job MUST send a `Filament\Notifications\Notification` to the requesting user with a download link upon completion.

#### Scenario: Queue PDF for large class

- GIVEN class with 150 total attempts (>= threshold 100)
- WHEN teacher clicks "Download PDF"
- THEN `GenerateClassReportPdf` job dispatched to `database` queue
- AND Filament notification with download link sent when job completes

#### Scenario: Queue Excel for large class

- GIVEN class with 150 total attempts (>= threshold 100)
- WHEN teacher clicks "Download Excel"
- THEN `GenerateClassReportExcel` job dispatched to `database` queue
- AND Filament notification with download link sent when job completes

### Requirement: Pass Rate Calculation

The system MUST determine pass/fail per attempt using `config('reports.pass_rate_threshold')` (default 0.6). An attempt passes when `score_obtained >= pass_rate_threshold * exam.max_score`. Pass rate per exam MUST be `(passing_attempts / total_attempts) * 100`, expressed as a percentage.

#### Scenario: Mixed pass/fail at 60% threshold

- GIVEN exam max_score=20, threshold=0.6 → passing threshold=12; 5 attempts scored 15, 12, 10, 8, 5
- WHEN pass rate computed
- THEN 2 passing (15, 12); pass rate = 40.00%

#### Scenario: All failing at 60% threshold

- GIVEN exam max_score=20, threshold=0.6; 3 attempts scored 5, 8, 10
- WHEN pass rate computed
- THEN 0 passing; pass rate = 0.00%
