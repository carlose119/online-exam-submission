<?php

use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;

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

    $student = User::create([
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
        ->post(route('student.exam.answer', [
            'attempt' => $attempt,
            'question' => $data['question'],
        ]), [
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

it('allows answer when timer has not expired', function () {
    $data = seedTimerTest(30);

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($data['student'])
        ->post(route('student.exam.answer', [
            'attempt' => $attempt,
            'question' => $data['question'],
        ]), [
            'options' => [$data['question']->options()->where('is_correct', true)->first()->id],
        ]);

    // Should redirect to take (not result) — timer is still valid.
    $response->assertRedirect();
    $response->assertSessionMissing('errors');

    $attempt->refresh();
    expect($attempt->finished_at)->toBeNull();
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
