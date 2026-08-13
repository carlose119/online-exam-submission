<?php

use App\Filament\Resources\ClassReportResource;
use App\Filament\Resources\ClassReportResource\Pages\ClassReport;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ClassReportService;
use App\Services\ReportFormatService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function reportClass(?User $teacher = null): SchoolClass
{
    $teacher ??= User::factory()->create(['role' => 'TEACHER']);

    return SchoolClass::create([
        'title' => 'Report Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => Str::random(8),
    ]);
}

// ---------------------------------------------------------------------------
// ClassReportTest — access control, report data, sync downloads, pass rate,
//                   download route auth + validation
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Access Control (query scope tests, following ClassResourceTest pattern)
// ---------------------------------------------------------------------------

it('teacher query scope returns only own classes', function () {
    $teacherA = User::create(['name' => 'Teacher A', 'email' => 'ta@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $teacherB = User::create(['name' => 'Teacher B', 'email' => 'tb@test.com', 'password' => 'password', 'role' => 'TEACHER']);

    SchoolClass::create(['title' => 'My Class', 'teacher_id' => $teacherA->id, 'invitation_code' => 'MYCLASS1']);
    SchoolClass::create(['title' => 'Other Class', 'teacher_id' => $teacherB->id, 'invitation_code' => 'OTHERCLS']);

    Auth::login($teacherA);
    $results = ClassReportResource::getEloquentQuery()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('My Class');
});

it('admin query scope returns all classes', function () {
    $admin = User::create(['name' => 'Admin', 'email' => 'admin2@test.com', 'password' => 'password', 'role' => 'ADMIN']);
    $teacherA = User::create(['name' => 'Teacher A', 'email' => 'ta2@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $teacherB = User::create(['name' => 'Teacher B', 'email' => 'tb2@test.com', 'password' => 'password', 'role' => 'TEACHER']);

    SchoolClass::create(['title' => 'Class A', 'teacher_id' => $teacherA->id, 'invitation_code' => 'CLASSA1']);
    SchoolClass::create(['title' => 'Class B', 'teacher_id' => $teacherB->id, 'invitation_code' => 'CLASSB1']);

    Auth::login($admin);
    $results = ClassReportResource::getEloquentQuery()->get();

    expect($results)->toHaveCount(2);
});

it('student access to class reports panel is denied via HTTP', function () {
    $student = User::create(['name' => 'Student', 'email' => 'stu@test.com', 'password' => 'password', 'role' => 'STUDENT']);

    $response = $this->actingAs($student)->get('/admin/class-reports');

    $response->assertForbidden();
});

it('guest is redirected to login for class reports', function () {
    $response = $this->get('/admin/class-reports');

    $response->assertRedirect('/admin/login');
});

it('allows own and admin direct report URLs but denies a foreign teacher', function () {
    config()->set('app.env', 'local');

    $owner = User::factory()->create(['role' => 'TEACHER']);
    $foreign = User::factory()->create(['role' => 'TEACHER']);
    $admin = User::factory()->create(['role' => 'ADMIN']);
    $class = reportClass($owner);
    $url = ClassReportResource::getUrl('report', ['record' => $class]);

    $this->actingAs($owner)->get($url)->assertOk();
    $this->flushSession();
    $this->actingAs($foreign)->get($url)->assertForbidden();
    $this->flushSession();
    $this->actingAs($admin)->get($url)->assertOk();
});

it('reauthorizes a synchronous export action', function () {
    config()->set('app.env', 'local');

    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = reportClass($teacher);

    $this->actingAs($teacher);
    $page = Livewire::test(ClassReport::class, ['record' => $class]);
    $class->update(['teacher_id' => User::factory()->create(['role' => 'TEACHER'])->id]);

    $page->call('mountAction', 'downloadPdf')->assertForbidden();
    expect(Storage::disk('reports')->allFiles())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Report Page Content (via service, matches ClassReportServiceTest pattern)
// ---------------------------------------------------------------------------

it('report data shows per-exam and overall stats', function () {
    $teacher = User::create(['name' => 'Alice Teacher', 'email' => 'alice2@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Math 101', 'description' => 'Basic Math', 'teacher_id' => $teacher->id, 'invitation_code' => 'MATH101A']);

    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Quiz 1', 'max_score' => 20, 'duration_minutes' => 30]);

    // 3 attempts: 15, 10, 5 → avg=10.00, pass_rate=33.33% (>=12 passes: only 15)
    $alice = User::create(['name' => 'Alice', 'email' => 'alice_stu@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob_stu@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $carol = User::create(['name' => 'Carol', 'email' => 'carol@test.com', 'password' => 'password', 'role' => 'STUDENT']);

    StudentAttempt::create(['student_id' => $alice->id, 'exam_id' => $exam->id, 'score_obtained' => 15, 'started_at' => now(), 'finished_at' => now()]);
    StudentAttempt::create(['student_id' => $bob->id, 'exam_id' => $exam->id, 'score_obtained' => 10, 'started_at' => now(), 'finished_at' => now()]);
    StudentAttempt::create(['student_id' => $carol->id, 'exam_id' => $exam->id, 'score_obtained' => 5, 'started_at' => now(), 'finished_at' => now()]);

    $service = app(ClassReportService::class);
    $result = $service->generate($class);

    expect($result['class']['title'])->toBe('Math 101');
    expect($result['teacher']['name'])->toBe('Alice Teacher');
    expect($result['class']['description'])->toBe('Basic Math');
    expect($result['exams'][0]['exam']['title'])->toBe('Quiz 1');
    expect($result['exams'][0]['stats']['attempts_count'])->toBe(3);
    expect($result['exams'][0]['stats']['pass_rate'])->toBe(33.33);
    expect($result['overall_stats']['total_attempts'])->toBe(3);
});

// ---------------------------------------------------------------------------
// Sync Download: PDF
// ---------------------------------------------------------------------------

it('generates PDF synchronously for a small class', function () {
    Storage::fake('reports');
    config()->set('reports.sync_threshold', 100);
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'Teacher', 'email' => 't_sync@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Small Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'SMALLPDF']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Quiz A', 'max_score' => 20, 'duration_minutes' => 30]);

    $student = User::create(['name' => 'Student 1', 'email' => 's1sync@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 18, 'started_at' => now(), 'finished_at' => now()]);

    $service = app(ClassReportService::class);
    $formatter = app(ReportFormatService::class);
    $data = $service->generate($class);
    $filename = $formatter->toPdf($data, $class);

    Storage::disk('reports')->assertExists($filename);
    expect($filename)->toEndWith('.pdf');
});

// ---------------------------------------------------------------------------
// Sync Download: Excel
// ---------------------------------------------------------------------------

it('generates Excel synchronously for a small class', function () {
    Storage::fake('reports');
    config()->set('reports.sync_threshold', 100);
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'Teacher', 'email' => 't_xlsx@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Excel Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'XLSXCLS']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Exam X', 'max_score' => 10, 'duration_minutes' => 15]);

    $student = User::create(['name' => 'S1', 'email' => 's1xlsx@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 8, 'started_at' => now(), 'finished_at' => now()]);

    $service = app(ClassReportService::class);
    $formatter = app(ReportFormatService::class);
    $data = $service->generate($class);
    $filename = $formatter->toExcel($data, $class);

    Storage::disk('reports')->assertExists($filename);
    expect($filename)->toEndWith('.xlsx');
});

// ---------------------------------------------------------------------------
// Pass Rate Calculation
// ---------------------------------------------------------------------------

it('computes pass rate correctly: mixed pass/fail at 60% threshold', function () {
    config()->set('reports.pass_rate_threshold', 0.6);
    $teacher = User::create(['name' => 'T', 'email' => 't_pass@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Pass Rate Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'PASSRATE']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Midterm', 'max_score' => 20, 'duration_minutes' => 60]);

    // 5 attempts: 15, 12 (pass), 10, 8, 5 (fail) → 2 passing, pass rate = 40%
    $scores = [15, 12, 10, 8, 5];
    foreach ($scores as $i => $score) {
        $student = User::create(['name' => "S{$i}", 'email' => "sp{$i}@test.com", 'password' => 'password', 'role' => 'STUDENT']);
        StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => $score, 'started_at' => now(), 'finished_at' => now()]);
    }

    $result = app(ClassReportService::class)->generate($class);

    expect($result['exams'][0]['stats']['pass_rate'])->toBe(40.00);
    expect($result['exams'][0]['stats']['attempts_count'])->toBe(5);
});

it('computes pass rate correctly: all failing at 60% threshold', function () {
    config()->set('reports.pass_rate_threshold', 0.6);
    $teacher = User::create(['name' => 'T', 'email' => 't_fail@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Fail Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'FAILCLS']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Hard Exam', 'max_score' => 20, 'duration_minutes' => 60]);

    $scores = [5, 8, 10];
    foreach ($scores as $i => $score) {
        $student = User::create(['name' => "F{$i}", 'email' => "f{$i}@test.com", 'password' => 'password', 'role' => 'STUDENT']);
        StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => $score, 'started_at' => now(), 'finished_at' => now()]);
    }

    $result = app(ClassReportService::class)->generate($class);

    expect($result['exams'][0]['stats']['pass_rate'])->toBe(0.00);
});

// ---------------------------------------------------------------------------
// Download Route: Auth + Validation (these are standard web routes, work fine)
// ---------------------------------------------------------------------------

it('allows authenticated teacher to download a report file', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'T', 'email' => 'tdown@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = reportClass($teacher);
    $filename = "class-{$class->id}-test.pdf";
    Storage::disk('reports')->put($filename, 'PDF content');

    $response = $this->actingAs($teacher)->get(route('reports.download', $filename));

    $response->assertOk();
    $response->assertHeader('Content-Disposition');
});

it('allows admin but denies a foreign teacher downloading an artifact', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    $owner = User::factory()->create(['role' => 'TEACHER']);
    $foreign = User::factory()->create(['role' => 'TEACHER']);
    $admin = User::factory()->create(['role' => 'ADMIN']);
    $class = reportClass($owner);
    $filename = "class-{$class->id}-test.pdf";
    Storage::disk('reports')->put($filename, 'content');
    $url = route('reports.download', $filename);

    $this->actingAs($foreign)->get($url)->assertForbidden();
    $this->flushSession();
    $this->actingAs($admin)->get($url)->assertOk();
});

it('rejects path traversal in download filename', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'T', 'email' => 'ttrav@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = reportClass($teacher);

    $response = $this->actingAs($teacher)->get('/admin/reports/download/foo/bar');

    $response->assertStatus(400);
});

it('returns 404 for non-existent report file', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'T', 'email' => 't404@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = reportClass($teacher);

    $response = $this->actingAs($teacher)->get(route('reports.download', "class-{$class->id}-nonexistent.pdf"));

    $response->assertNotFound();
});

it('denies student access to report download route', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $student = User::create(['name' => 'Student', 'email' => 'st_down@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    $class = reportClass();

    $response = $this->actingAs($student)->get(route('reports.download', "class-{$class->id}-test.pdf"));

    $response->assertForbidden();
});

it('redirects unauthenticated user from download route', function () {
    $class = reportClass();
    $response = $this->get(route('reports.download', "class-{$class->id}-test.pdf"));

    $response->assertRedirect('/login');
});
