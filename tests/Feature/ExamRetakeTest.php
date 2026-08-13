<?php

use App\Livewire\Student\ExamStart;
use App\Models\Exam;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ExamAllowanceService;
use App\Services\ExamAttemptCreator;
use Illuminate\Support\Facades\DB;
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

it('shows effective limits and makes a retake available while preserving result history', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    app(ExamAllowanceService::class)->save($exam, $student, 2, 15);

    $first = app(ExamAttemptCreator::class)->create($exam, $student->id);
    $first->update(['finished_at' => now()->subMinute(), 'score_obtained' => 70]);
    $second = app(ExamAttemptCreator::class)->create($exam, $student->id);
    $second->update(['finished_at' => now(), 'score_obtained' => 80]);

    $this->actingAs($student)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('student.exam.start', $exam))
        ->assertSee('1 remaining')
        ->assertSee(route('student.exam.result', $first))
        ->assertSee(route('student.exam.result', $second));

    Livewire::actingAs($student)->test(ExamStart::class, ['exam' => $exam])
        ->assertSee('60 min')
        ->assertSee('3 total attempts')
        ->assertSee('2 used')
        ->assertSee('1 remaining');
});

it('shows an unfinished attempt as active without exposing another student allowance', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $otherStudent = User::factory()->create(['role' => 'STUDENT']);
    $exam->classroom->students()->attach($otherStudent);
    app(ExamAllowanceService::class)->save($exam, $student, 1, 5);
    app(ExamAllowanceService::class)->save($exam, $otherStudent, 8, 700);

    Livewire::actingAs($student)->test(ExamStart::class, ['exam' => $exam])
        ->assertSee('50 min')
        ->assertSee('2 total attempts')
        ->assertDontSee('745 min')
        ->assertDontSee('9 total attempts');

    $activeAttempt = app(ExamAttemptCreator::class)->create($exam, $student->id);

    $this->actingAs($student)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Examenes activos')
        ->assertSee(route('student.exam.take', $activeAttempt))
        ->assertDontSee(route('student.exam.result', $activeAttempt))
        ->assertDontSee('745 min')
        ->assertDontSee('9 remaining');
});

it('finalizes an expired attempt before showing history and an eligible retake', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    app(ExamAllowanceService::class)->save($exam, $student, 1, 0);
    $expired = app(ExamAttemptCreator::class)->create($exam, $student->id);
    $expired->update(['started_at' => now()->subHour()]);

    $this->actingAs($student)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('student.exam.start', $exam))
        ->assertSee(route('student.exam.result', $expired))
        ->assertDontSee(route('student.exam.take', $expired));

    expect($expired->fresh()->finished_at)->not->toBeNull()
        ->and((float) $expired->fresh()->score_obtained)->toBe(0.0);
});

it('keeps dashboard query growth bounded as subscribed exams increase', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $this->actingAs($student)->get(route('dashboard'))->assertOk();

    $queriesFor = function (int $examCount) use ($exam, $student): int {
        while ($exam->classroom->exams()->count() < $examCount) {
            $exam->classroom->exams()->create([
                'title' => 'Bounded Exam '.$exam->classroom->exams()->count(),
                'duration_minutes' => 30,
                'max_score' => 10,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($student)->get(route('dashboard'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $oneExamQueries = $queriesFor(1);
    $sixExamQueries = $queriesFor(6);

    expect($sixExamQueries)->toBe($oneExamQueries);
});

it('orders tied completed history by attempt number without exposing another student result', function () {
    ['exam' => $exam, 'student' => $student] = seedExamRetake();
    $otherStudent = User::factory()->create(['role' => 'STUDENT']);
    $finishedAt = now();
    $first = StudentAttempt::create([
        'student_id' => $student->id, 'exam_id' => $exam->id, 'attempt_number' => 1,
        'started_at' => now()->subHour(), 'finished_at' => $finishedAt, 'score_obtained' => 10,
    ]);
    $second = StudentAttempt::create([
        'student_id' => $student->id, 'exam_id' => $exam->id, 'attempt_number' => 2,
        'started_at' => now()->subHour(), 'finished_at' => $finishedAt, 'score_obtained' => 20,
    ]);
    $foreign = StudentAttempt::create([
        'student_id' => $otherStudent->id, 'exam_id' => $exam->id, 'attempt_number' => 1,
        'started_at' => now()->subHour(), 'finished_at' => $finishedAt, 'score_obtained' => 99,
    ]);

    $this->actingAs($student)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([
            route('student.exam.result', $second),
            route('student.exam.result', $first),
        ])
        ->assertDontSee(route('student.exam.result', $foreign));
});
