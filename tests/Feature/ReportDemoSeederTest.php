<?php

use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ClassReportService;
use Illuminate\Support\Facades\Hash;

function seedReportDemo(): void
{
    test()->artisan('db:seed', ['--class' => 'ReportDemoSeeder', '--no-interaction' => true])
        ->expectsOutput('Report demo created. Open /admin/class-reports with the documented demo teacher account.')
        ->assertSuccessful();
}

it('seeds a usable teacher and a coherent scored class report', function (string $environment) {
    app()->instance('env', $environment);
    seedReportDemo();

    $teacher = User::where('email', 'pdf.teacher@example.test')->sole();
    $student = User::where('email', 'pdf.student@example.test')->sole();
    $class = SchoolClass::where('invitation_code', 'PDFDEMO2026')->sole();
    $exam = $class->exams()->sole();
    $attempt = StudentAttempt::sole();
    $answer = $attempt->answers()->sole();
    $question = $exam->questions()->sole();

    expect($teacher->role)->toBe('TEACHER')
        ->and(Hash::check('LocalPdfDemo!2026', $teacher->password))->toBeTrue()
        ->and($student->role)->toBe('STUDENT')
        ->and($student->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('LocalPdfDemo!2026', $student->password))->toBeTrue()
        ->and($class->teacher_id)->toBe($teacher->id)
        ->and($class->students()->sole()->id)->toBe($student->id)
        ->and($attempt->student_id)->toBe($student->id)
        ->and($attempt->exam_id)->toBe($exam->id)
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->allowed_duration_minutes)->toBe(30)
        ->and($attempt->finished_at->greaterThan($attempt->started_at))->toBeTrue()
        ->and($attempt->finished_at->lessThanOrEqualTo($attempt->deadline()))->toBeTrue()
        ->and($answer->question_id)->toBe($question->id)
        ->and($question->options()->count())->toBe(2)
        ->and($question->options()->whereKey($answer->answer_option_id)->sole()->is_correct)->toBeTrue();

    $report = app(ClassReportService::class)->generate($class);
    expect($report['overall_stats']['total_attempts'])->toBe(1)
        ->and($report['exams'][0]['attempts'][0]['student_name'])->toBe('PDF Demo Student')
        ->and((float) $report['exams'][0]['attempts'][0]['score_obtained'])->toBe(10.0);

    // Match the local panel access convention used by ClassReportTest.
    config()->set('app.env', 'local');
    $this->actingAs($teacher)->get('/admin/class-reports/'.$class->id.'/report')
        ->assertOk()->assertSee('PDF Demo Class')->assertSee('Download PDF')->assertSee('PDF Demo Student');
})->with(['local', 'testing']);

it('leaves the seeded report and changed credentials untouched on a repeated run', function () {
    seedReportDemo();
    $teacher = User::where('email', 'pdf.teacher@example.test')->sole();
    $teacher->update(['name' => 'Renamed teacher', 'password' => 'ChangedPassword!2026']);
    $before = $teacher->fresh()->getAttributes();
    $class = SchoolClass::sole();
    $report = app(ClassReportService::class)->generate($class);

    $this->artisan('db:seed', ['--class' => 'ReportDemoSeeder', '--no-interaction' => true])
        ->expectsOutput('Demo identifiers already exist; no data changed.')
        ->assertSuccessful();

    expect(User::count())->toBe(2)
        ->and(SchoolClass::count())->toBe(1)
        ->and(Exam::count())->toBe(1)
        ->and(StudentAttempt::count())->toBe(1)
        ->and($class->students()->count())->toBe(1)
        ->and($teacher->fresh()->getAttributes())->toBe($before)
        ->and(app(ClassReportService::class)->generate($class->fresh()))->toBe($report);
});

it('does not adopt or change existing records with a demo identifier', function (string $collision) {
    $user = User::factory()->create([
        'email' => $collision === 'class' ? 'existing@example.test' : $collision,
        'role' => 'TEACHER',
    ]);
    $class = SchoolClass::create([
        'teacher_id' => $user->id, 'title' => 'Existing class',
        'invitation_code' => $collision === 'class' ? 'PDFDEMO2026' : 'EXISTING',
    ]);
    $beforeUser = $user->fresh()->getAttributes();
    $beforeClass = $class->fresh()->getAttributes();

    $this->artisan('db:seed', ['--class' => 'ReportDemoSeeder', '--no-interaction' => true])
        ->expectsOutput('Demo identifiers already exist; no data changed.')
        ->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(SchoolClass::count())->toBe(1)
        ->and(Exam::count())->toBe(0)
        ->and(StudentAttempt::count())->toBe(0)
        ->and($class->students()->count())->toBe(0)
        ->and($user->fresh()->getAttributes())->toBe($beforeUser)
        ->and($class->fresh()->getAttributes())->toBe($beforeClass);
})->with(['pdf.teacher@example.test', 'pdf.student@example.test', 'class']);

it('refuses demo credentials outside local and testing without changing data', function (string $environment) {
    app()->instance('env', $environment);

    expect(fn () => $this->artisan('db:seed', ['--class' => 'ReportDemoSeeder', '--force' => true])->run())
        ->toThrow(RuntimeException::class, 'ReportDemoSeeder is only available in local or testing environments.');
    expect(User::count())->toBe(0)
        ->and(SchoolClass::count())->toBe(0)
        ->and(Exam::count())->toBe(0)
        ->and(StudentAttempt::count())->toBe(0);
})->with(['production', 'staging']);
