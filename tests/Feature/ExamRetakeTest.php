<?php

use App\Livewire\Student\ExamStart;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ExamAllowanceService;
use App\Services\ExamAttemptCreator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function seedExamRetake(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create([
        'title' => 'Retake Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'RETAKE01',
    ]);
    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Retake Exam',
        'duration_minutes' => 45,
        'max_score' => 100,
    ]);
    $student = User::factory()->create(['role' => 'STUDENT']);
    $class->students()->attach($student);

    return compact('exam', 'student');
}

it('starts exactly through the effective total with stable sequence and duration snapshots', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $allowances = app(ExamAllowanceService::class);
    $creator = app(ExamAttemptCreator::class);
    $allowances->save($exam, $student, 2, 15);

    foreach ([1, 2, 3] as $number) {
        $attempt = $creator->create($exam, $student->id);
        expect($attempt->attempt_number)->toBe($number)
            ->and($attempt->allowed_duration_minutes)->toBe(60);
        $attempt->update(['finished_at' => now(), 'score_obtained' => 0]);
    }

    expect(fn () => $creator->create($exam, $student->id))
        ->toThrow(HttpException::class, 'You have already taken this exam.');
    expect(StudentAttempt::count())->toBe(3);
});

it('rejects a new attempt while the current attempt is unfinished', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    app(ExamAllowanceService::class)->save($exam, $student, 1, 0);
    $creator = app(ExamAttemptCreator::class);
    $first = $creator->create($exam, $student->id);

    expect(fn () => $creator->create($exam, $student->id))->toThrow(HttpException::class);
    expect(StudentAttempt::sole()->is($first))->toBeTrue();
});

it('keeps completed attempt snapshots unchanged when later allowances change', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $allowances = app(ExamAllowanceService::class);
    $allowances->save($exam, $student, 1, 10);
    $first = app(ExamAttemptCreator::class)->create($exam, $student->id);
    $first->update(['finished_at' => now(), 'score_obtained' => 80]);

    $allowances->save($exam, $student, 1, 30);

    expect($first->fresh()->attempt_number)->toBe(1)
        ->and($first->fresh()->allowed_duration_minutes)->toBe(55)
        ->and((float) $first->fresh()->score_obtained)->toBe(80.0);
});

it('prevents reducing an allowance below consumed additional attempts', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $allowances = app(ExamAllowanceService::class);
    $allowances->save($exam, $student, 2, 0);
    $creator = app(ExamAttemptCreator::class);

    foreach ([1, 2] as $unused) {
        $creator->create($exam, $student->id)->update(['finished_at' => now(), 'score_obtained' => 0]);
    }

    expect(fn () => $allowances->save($exam, $student, 0, 0))->toThrow(ValidationException::class);
    expect($allowances->limitsFor($exam, $student)->totalAttempts)->toBe(3);
});

it('keeps the base limit at one attempt', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $creator = app(ExamAttemptCreator::class);
    $creator->create($exam, $student->id)->update(['finished_at' => now(), 'score_obtained' => 0]);

    expect(fn () => $creator->create($exam, $student->id))->toThrow(HttpException::class);
    expect(StudentAttempt::count())->toBe(1);
});

it('shows the start guard when a completed student still has an effective attempt', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $allowances = app(ExamAllowanceService::class);
    $allowances->save($exam, $student, 1, 0);
    app(ExamAttemptCreator::class)->create($exam, $student->id)
        ->update(['finished_at' => now(), 'score_obtained' => 0]);

    Livewire::actingAs($student)->test(ExamStart::class, ['exam' => $exam])->assertOk();
});
