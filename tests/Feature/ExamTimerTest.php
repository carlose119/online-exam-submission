<?php

use App\Http\Controllers\Student\ExamController;
use App\Livewire\Student\ExamTake;
use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ExamAllowanceService;
use App\Services\ExamAttemptCreator;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function seedTimerTest(int $durationMinutes = 30): array
{
    $teacher = User::create([
        'name' => 'Timer Teacher',
        'email' => 'timer-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Timer Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'TIMER001',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Timer Exam',
        'duration_minutes' => $durationMinutes,
        'max_score' => 10,
    ]);

    $question = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Timer question?',
        'type' => 'SINGLE',
        'points' => 10,
        'order' => 0,
    ]);

    AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Correct',
        'is_correct' => true,
    ]);

    AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Wrong',
        'is_correct' => false,
    ]);

    $student = User::factory()->create([
        'name' => 'Timer Student',
        'email' => 'timer-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    return compact('teacher', 'class', 'exam', 'question', 'student');
}

// ---------------------------------------------------------------------------
// Timer expired → auto-submit on take route
// ---------------------------------------------------------------------------

it('auto-submits and redirects to result when timer expires on take', function () {
    $data = seedTimerTest(30);

    // Create an attempt whose timer expired 5 minutes ago.
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now()->subMinutes(35), // 30 min exam, started 35 min ago
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.take', $attempt));

    // Should redirect to result (auto-submit fired).
    $response->assertRedirect(route('student.exam.result', $attempt));

    // Attempt should now be graded.
    $attempt->refresh();
    expect($attempt->finished_at)->not->toBeNull();
    expect((float) $attempt->score_obtained)->toBe(0.0); // No answers = 0
});

// ---------------------------------------------------------------------------
// Timer expired → auto-submit on answer route
// ---------------------------------------------------------------------------

it('auto-submits and redirects to result when timer expires on answer', function () {
    $data = seedTimerTest(30);

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now()->subMinutes(35),
    ]);

    $response = $this->actingAs($data['student'])
        ->post(signedExamAnswerUrl($attempt, $data['question']), [
            'options' => [1],
        ]);

    // Should redirect to result (auto-submit fired before processing answer).
    $response->assertRedirect(route('student.exam.result', $attempt));

    $attempt->refresh();
    expect($attempt->finished_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Timer NOT expired → normal access to take
// ---------------------------------------------------------------------------

it('allows access to take when timer has not expired', function () {
    $data = seedTimerTest(30);

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.take', $attempt));

    $response->assertStatus(200);
    $response->assertSee('Timer question?');

    // Attempt should NOT be graded.
    $attempt->refresh();
    expect($attempt->finished_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Timer NOT expired → normal answer processing
// ---------------------------------------------------------------------------

it('allows and finalizes the last answer when timer has not expired', function () {
    $data = seedTimerTest(30);

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($data['student'])
        ->post(signedExamAnswerUrl($attempt, $data['question']), [
            'options' => [$data['question']->options()->where('is_correct', true)->first()->id],
        ]);

    $response->assertRedirect(route('student.exam.result', $attempt));
    $response->assertSessionMissing('errors');

    $attempt->refresh();
    expect($attempt->finished_at)->not->toBeNull();
});

it('snapshots accommodated time and keeps the deadline after revocation', function () {
    $data = seedTimerTest(30);
    $allowances = app(ExamAllowanceService::class);
    $allowances->save($data['exam'], $data['student'], 0, 15);
    $attempt = app(ExamAttemptCreator::class)->create($data['exam'], $data['student']->id);
    $deadline = $attempt->started_at->copy()->addMinutes(45);

    $allowances->save($data['exam'], $data['student'], 0, 0);
    $this->travel(31)->minutes();

    $response = $this->actingAs($data['student'])->get(route('student.exam.take', $attempt));
    $response->assertOk()
        ->assertSee($deadline->toIso8601String(), false)
        ->assertSee((string) $deadline->copy()->addMinutes(10)->timestamp, false);
    expect($attempt->fresh()->allowed_duration_minutes)->toBe(45)
        ->and($attempt->deadline()->equalTo($deadline))->toBeTrue()
        ->and($attempt->finished_at)->toBeNull();
});

it('does not extend an active deadline after allowance and exam duration increases', function () {
    $data = seedTimerTest(30);
    $attempt = app(ExamAttemptCreator::class)->create($data['exam'], $data['student']->id);
    $deadline = $attempt->deadline();

    app(ExamAllowanceService::class)->save($data['exam'], $data['student'], 0, 30);
    $data['exam']->update(['duration_minutes' => 90]);
    $this->travel(31)->minutes();

    $this->actingAs($data['student'])
        ->get(route('student.exam.take', $attempt))
        ->assertRedirect(route('student.exam.result', $attempt));
    $attempt->refresh();
    expect($attempt->allowed_duration_minutes)->toBe(30)
        ->and($attempt->deadline()->equalTo($deadline))->toBeTrue()
        ->and($attempt->finished_at)->not->toBeNull();
});

it('backfills a legacy active deadline without destructive rollback', function () {
    $data = seedTimerTest(30);
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'allowed_duration_minutes' => null,
        'started_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_08_10_000000_snapshot_legacy_active_attempt_durations.php');

    $migration->up();
    $migration->down();
    $data['exam']->update(['duration_minutes' => 90]);

    $attempt->refresh();
    expect($attempt->allowed_duration_minutes)->toBe(30)
        ->and($attempt->deadline()->equalTo($attempt->started_at->copy()->addMinutes(30)))->toBeTrue();
});

it('enforces the snapshotted deadline on explicit submit', function () {
    $data = seedTimerTest(30);
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'allowed_duration_minutes' => 15,
        'started_at' => now()->subMinutes(16),
    ]);
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $data['question']->id,
        'answer_option_id' => $data['question']->options()->where('is_correct', true)->value('id'),
    ]);
    $this->mock(ExamController::class, function ($controller): void {
        $controller->shouldNotReceive('submit');
    });

    $this->actingAs($data['student'])
        ->post(route('student.exam.submit', $attempt))
        ->assertRedirect(route('student.exam.result', $attempt));

    $attempt->refresh();
    expect($attempt->finished_at)->not->toBeNull()
        ->and((float) $attempt->score_obtained)->toBe(10.0);
});

it('preserves exact-deadline answer compatibility', function () {
    $this->travelTo(now()->startOfSecond());
    $data = seedTimerTest(30);
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'allowed_duration_minutes' => 30,
        'started_at' => now()->subMinutes(30),
    ]);
    $correct = $data['question']->options()->where('is_correct', true)->first();

    expect($attempt->isExpired())->toBeFalse();
    $this->actingAs($data['student'])
        ->post(signedExamAnswerUrl($attempt, $data['question']), ['options' => [$correct->id]])
        ->assertRedirect(route('student.exam.result', $attempt));

    expect($attempt->answers()->where('answer_option_id', $correct->id)->exists())->toBeTrue();
});

it('rejects a late Livewire save and next answer before persisting it', function () {
    $data = seedTimerTest(30);
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);
    $correctOption = $data['question']->options()->where('is_correct', true)->first();

    $component = Livewire::actingAs($data['student'])
        ->test(ExamTake::class, ['attempt' => $attempt])
        ->set("singleSelections.{$data['question']->id}", (string) $correctOption->id);

    $this->travel(31)->minutes();

    $component
        ->call('saveAndNext')
        ->assertRedirect(route('student.exam.result', $attempt));

    $attempt->refresh();
    expect(StudentAnswer::where('student_attempt_id', $attempt->id)->count())->toBe(0)
        ->and($attempt->finished_at)->not->toBeNull()
        ->and((float) $attempt->score_obtained)->toBe(0.0);
});

it('rejects a late Livewire finalize answer before grading it', function () {
    $data = seedTimerTest(30);
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);
    $wrongOption = $data['question']->options()->where('is_correct', false)->first();
    $correctOption = $data['question']->options()->where('is_correct', true)->first();
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $data['question']->id,
        'answer_option_id' => $wrongOption->id,
    ]);

    $component = Livewire::actingAs($data['student'])
        ->test(ExamTake::class, ['attempt' => $attempt])
        ->set("singleSelections.{$data['question']->id}", (string) $correctOption->id);

    $this->travel(31)->minutes();

    $component
        ->call('finalize')
        ->assertRedirect(route('student.exam.result', $attempt));

    $attempt->refresh();
    expect(StudentAnswer::where('student_attempt_id', $attempt->id)->count())->toBe(1)
        ->and(StudentAnswer::where('student_attempt_id', $attempt->id)->value('answer_option_id'))->toBe($wrongOption->id)
        ->and($attempt->finished_at)->not->toBeNull()
        ->and((float) $attempt->score_obtained)->toBe(0.0);
});

it('persists an unexpired Livewire save and next answer normally', function () {
    $data = seedTimerTest(30);
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);
    $correctOption = $data['question']->options()->where('is_correct', true)->first();

    Livewire::actingAs($data['student'])
        ->test(ExamTake::class, ['attempt' => $attempt])
        ->set("singleSelections.{$data['question']->id}", (string) $correctOption->id)
        ->call('saveAndNext')
        ->assertRedirect(route('student.exam.take', ['attempt' => $attempt, 'q' => 1]));

    $attempt->refresh();
    expect(StudentAnswer::where('student_attempt_id', $attempt->id)->count())->toBe(1)
        ->and(StudentAnswer::where('student_attempt_id', $attempt->id)->value('answer_option_id'))->toBe($correctOption->id)
        ->and($attempt->finished_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Browser-close mid-exam → auto-submit on resume when timer expired
// ---------------------------------------------------------------------------

it('auto-submits on resume after browser close when timer expired', function () {
    $data = seedTimerTest(30);

    // Simulate: student started 2 hours ago, closed browser, returns now.
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now()->subHours(2),
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.take', $attempt));

    $response->assertRedirect(route('student.exam.result', $attempt));

    $attempt->refresh();
    expect($attempt->finished_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Already-graded attempt is not re-graded by timer middleware
// ---------------------------------------------------------------------------

it('does not re-grade an already finished attempt when timer is checked', function () {
    $data = seedTimerTest(30);

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now()->subHours(2),
        'finished_at' => now()->subHour(1),
        'score_obtained' => 5.0,
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.take', $attempt));

    // Should redirect to result (already graded).
    $response->assertRedirect(route('student.exam.result', $attempt));

    $attempt->refresh();
    // Score should remain unchanged (idempotent — the grading service guards against re-grade).
    expect((float) $attempt->score_obtained)->toBe(5.0);
});
