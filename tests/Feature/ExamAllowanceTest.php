<?php

use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ExamAllowanceService;
use Illuminate\Validation\ValidationException;

function seedExamAllowance(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create([
        'title' => 'Allowance Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ALLOWANCE1',
    ]);
    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Allowance Exam',
        'duration_minutes' => 60,
        'max_score' => 100,
    ]);
    $students = User::factory()->count(2)->create(['role' => 'STUDENT']);
    $class->students()->attach($students->modelKeys());

    return compact('class', 'exam', 'students');
}

it('defaults to one attempt and the base duration', function () {
    $data = seedExamAllowance();

    $limits = app(ExamAllowanceService::class)->limitsFor($data['exam'], $data['students']->first());

    expect($limits->totalAttempts)->toBe(1)
        ->and($limits->durationMinutes)->toBe(60);
});

it('keeps student-specific additions isolated', function () {
    $data = seedExamAllowance();
    [$student, $otherStudent] = $data['students'];
    $service = app(ExamAllowanceService::class);
    $service->save($data['exam'], $student, 2, 30);

    $studentLimits = $service->limitsFor($data['exam'], $student);
    $otherStudentLimits = $service->limitsFor($data['exam'], $otherStudent);

    expect($studentLimits->totalAttempts)->toBe(3)
        ->and($studentLimits->durationMinutes)->toBe(90)
        ->and($otherStudentLimits->totalAttempts)->toBe(1)
        ->and($otherStudentLimits->durationMinutes)->toBe(60)
        ->and($data['exam']->allowances()->count())->toBe(1)
        ->and($otherStudent->examAllowances()->doesntExist())->toBeTrue();
});

it('keeps existing attempt timing unchanged when an allowance changes', function () {
    $data = seedExamAllowance();
    $student = $data['students']->first();
    $service = app(ExamAllowanceService::class);
    $service->save($data['exam'], $student, 0, 15);
    $limits = $service->limitsFor($data['exam'], $student);
    $attempt = StudentAttempt::create([
        'student_id' => $student->id,
        'exam_id' => $data['exam']->id,
        'attempt_number' => 1,
        'allowed_duration_minutes' => $limits->durationMinutes,
        'started_at' => now(),
    ]);

    $service->save($data['exam'], $student, 0, 45);

    expect($attempt->fresh()->allowed_duration_minutes)->toBe(75)
        ->and($data['exam']->allowances()->count())->toBe(1);
});

it('rejects an allowance for an unenrolled student', function () {
    $data = seedExamAllowance();
    $unenrolled = User::factory()->create(['role' => 'STUDENT']);

    expect(fn () => app(ExamAllowanceService::class)->save($data['exam'], $unenrolled, 1, 10))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('exam_allowances', ['student_id' => $unenrolled->id]);
});

it('rejects negative allowance values', function () {
    $data = seedExamAllowance();
    $student = $data['students']->first();

    expect(fn () => app(ExamAllowanceService::class)->save($data['exam'], $student, -1, 0))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('exam_allowances', 0);
});
