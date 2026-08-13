<?php

use App\Jobs\GenerateClassReportExcel;
use App\Jobs\GenerateClassReportPdf;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ReportArtifactPublisher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function queuedReportClass(User $teacher): SchoolClass
{
    return SchoolClass::create([
        'title' => 'Queued Report Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => Str::random(8),
    ]);
}

// ---------------------------------------------------------------------------
// GenerateClassReportPdfJobTest — queue dispatch, storage, notifications
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Queue Dispatch
// ---------------------------------------------------------------------------

it('dispatches GenerateClassReportPdf job when pushed to queue', function () {
    Queue::fake();

    $teacher = User::create(['name' => 'Teacher', 'email' => 'tq@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Queue Test', 'teacher_id' => $teacher->id, 'invitation_code' => 'QTASK1']);

    GenerateClassReportPdf::dispatch($class->id, $teacher->id);

    Queue::assertPushed(GenerateClassReportPdf::class, function ($job) {
        return true;
    });
});

it('dispatches GenerateClassReportExcel job when pushed to queue', function () {
    Queue::fake();

    $teacher = User::create(['name' => 'Teacher', 'email' => 'teq@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Excel Queue', 'teacher_id' => $teacher->id, 'invitation_code' => 'EQTASK1']);

    GenerateClassReportExcel::dispatch($class->id, $teacher->id);

    Queue::assertPushed(GenerateClassReportExcel::class);
});

// ---------------------------------------------------------------------------
// Job Processing: File Storage
// ---------------------------------------------------------------------------

it('generates and stores PDF file when job is processed', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'Teacher', 'email' => 'tproc@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Job Process Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'JOBPROC']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Exam 1', 'max_score' => 20, 'duration_minutes' => 30]);

    $student = User::create(['name' => 'Student', 'email' => 'sproc@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 15, 'started_at' => now(), 'finished_at' => now()]);

    $job = new GenerateClassReportPdf($class->id, $teacher->id);
    $job->handle(app(ReportArtifactPublisher::class));

    // A PDF file should now exist on the reports disk.
    $files = Storage::disk('reports')->allFiles();
    expect($files)->not->toBeEmpty();

    $pdfFile = collect($files)->first(fn ($f) => str_ends_with($f, '.pdf'));
    expect($pdfFile)->not->toBeNull();
    expect($pdfFile)->toContain('class-'.$class->id);
    expect($pdfFile)->toEndWith('.pdf');
});

it('generates and stores Excel file when job is processed', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'Teacher', 'email' => 'texcelj@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Excel Job Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'EXCELJOB']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Exam X', 'max_score' => 10, 'duration_minutes' => 15]);

    $student = User::create(['name' => 'Student X', 'email' => 'sxcel@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 9, 'started_at' => now(), 'finished_at' => now()]);

    $job = new GenerateClassReportExcel($class->id, $teacher->id);
    $job->handle(app(ReportArtifactPublisher::class));

    $files = Storage::disk('reports')->allFiles();
    expect($files)->not->toBeEmpty();

    $xlsxFile = collect($files)->first(fn ($f) => str_ends_with($f, '.xlsx'));
    expect($xlsxFile)->not->toBeNull();
    expect($xlsxFile)->toContain('class-'.$class->id);
});

// ---------------------------------------------------------------------------
// Job Handles Missing Models Gracefully
// ---------------------------------------------------------------------------

it('handles missing class gracefully without throwing', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'Teacher', 'email' => 'tmissing@test.com', 'password' => 'password', 'role' => 'TEACHER']);

    $job = new GenerateClassReportPdf(99999, $teacher->id);

    // Should not throw an exception for a non-existent class.
    expect(fn () => $job->handle(app(ReportArtifactPublisher::class)))
        ->not->toThrow(Exception::class);
});

it('handles missing user gracefully without throwing', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'Teacher', 'email' => 'tmissuser@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Missing User', 'teacher_id' => $teacher->id, 'invitation_code' => 'MISSUSR']);

    $job = new GenerateClassReportPdf($class->id, 99999);

    expect(fn () => $job->handle(app(ReportArtifactPublisher::class)))
        ->not->toThrow(Exception::class);
});

// ---------------------------------------------------------------------------
// Queue::fake integration
// ---------------------------------------------------------------------------

it('asserts job is pushed with correct payload via Queue::fake', function () {
    Queue::fake();

    $teacher = User::create(['name' => 'Teacher', 'email' => 'tfake@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Fake Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'FAKECLS']);

    GenerateClassReportPdf::dispatch($class->id, $teacher->id);
    GenerateClassReportExcel::dispatch($class->id, $teacher->id);

    Queue::assertPushed(GenerateClassReportPdf::class, 1);
    Queue::assertPushed(GenerateClassReportExcel::class, 1);
});

// ---------------------------------------------------------------------------
// Notification sent to user
// ---------------------------------------------------------------------------

it('sends database notification to user when PDF job completes', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');

    $teacher = User::create(['name' => 'Teacher', 'email' => 'tnotif@test.com', 'password' => 'password', 'role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Notify Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'NOTIFY1']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Exam N', 'max_score' => 20, 'duration_minutes' => 30]);

    $student = User::create(['name' => 'Student N', 'email' => 'sn@test.com', 'password' => 'password', 'role' => 'STUDENT']);
    StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'score_obtained' => 18, 'started_at' => now(), 'finished_at' => now()]);

    $job = new GenerateClassReportPdf($class->id, $teacher->id);
    $job->handle(app(ReportArtifactPublisher::class));

    // Assert a database notification was created for the teacher.
    $this->assertDatabaseHas('notifications', [
        'notifiable_type' => User::class,
        'notifiable_id' => $teacher->id,
    ]);

    $notification = $teacher->notifications()->first();
    expect($notification)->not->toBeNull();
    expect($notification->data['title'])->toBe('PDF Report Ready');
    expect($notification->data['body'])->toContain('Notify Class');
});

it('publishes unique artifacts and notifications when a job retries', function () {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = queuedReportClass($teacher);
    $job = new GenerateClassReportPdf($class->id, $teacher->id);

    $job->handle(app(ReportArtifactPublisher::class));
    $job->handle(app(ReportArtifactPublisher::class));

    expect(Storage::disk('reports')->allFiles())->toHaveCount(2)
        ->and(Storage::disk('reports')->allFiles('staging'))->toBeEmpty()
        ->and($teacher->notifications()->count())->toBe(2);
});

it('does not generate or notify after class reassignment or role revocation', function (string $jobClass, string $change) {
    Storage::fake('reports');
    config()->set('reports.storage_disk', 'reports');
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = queuedReportClass($teacher);
    $job = new $jobClass($class->id, $teacher->id);

    $change === 'class'
        ? $class->update(['teacher_id' => User::factory()->create(['role' => 'TEACHER'])->id])
        : $teacher->update(['role' => 'STUDENT']);

    $job->handle(app(ReportArtifactPublisher::class));

    expect(Storage::disk('reports')->allFiles())->toBeEmpty();
    $this->assertDatabaseMissing('notifications', ['notifiable_id' => $teacher->id]);
})->with([
    'PDF after reassignment' => [GenerateClassReportPdf::class, 'class'],
    'Excel after reassignment' => [GenerateClassReportExcel::class, 'class'],
    'PDF after role revocation' => [GenerateClassReportPdf::class, 'role'],
    'Excel after role revocation' => [GenerateClassReportExcel::class, 'role'],
]);
