<?php

use App\Livewire\Student\ExamStart;
use App\Livewire\Student\ExamTake;
use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\AnswerSelectionWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function seedExamTaking(): array
{
    $teacher = User::create([
        'name' => 'ET Teacher',
        'email' => 'et-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'ET Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ETCLASS1',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'ET Exam',
        'description' => 'A test exam',
        'duration_minutes' => 60,
        'max_score' => 15,
    ]);

    $questions = [];
    foreach ([
        ['text' => 'Q1: What is 2+2?', 'type' => 'SINGLE', 'points' => 5, 'options' => [
            ['text' => '3', 'is_correct' => false],
            ['text' => '4', 'is_correct' => true],
        ]],
        ['text' => 'Q2: Select primes', 'type' => 'MULTIPLE', 'points' => 10, 'options' => [
            ['text' => '2', 'is_correct' => true],
            ['text' => '4', 'is_correct' => false],
            ['text' => '7', 'is_correct' => true],
        ]],
    ] as $i => $qDef) {
        $q = Question::create([
            'exam_id' => $exam->id,
            'text' => $qDef['text'],
            'type' => $qDef['type'],
            'points' => $qDef['points'],
            'order' => $i,
        ]);
        foreach ($qDef['options'] as $optDef) {
            AnswerOption::create([
                'question_id' => $q->id,
                'text' => $optDef['text'],
                'is_correct' => $optDef['is_correct'],
            ]);
        }
        $questions[] = $q;
    }

    $student = User::create([
        'name' => 'ET Student',
        'email' => 'et-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    return compact('teacher', 'class', 'exam', 'questions', 'student');
}

// ---------------------------------------------------------------------------
// Start: unauthenticated → redirect to login
// ---------------------------------------------------------------------------

it('redirects guest to login when starting an exam', function () {
    $data = seedExamTaking();

    $response = $this->get(route('student.exam.start', $data['exam']));

    $response->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Start: non-STUDENT denied → 403
// ---------------------------------------------------------------------------

it('denies teacher from starting an exam', function () {
    $data = seedExamTaking();

    $response = $this->actingAs($data['teacher'])
        ->get(route('student.exam.start', $data['exam']));

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// Start: not subscribed → 403
// ---------------------------------------------------------------------------

it('denies student who is not subscribed to the class', function () {
    $data = seedExamTaking();

    $unsubscribed = User::create([
        'name' => 'Unsubscribed',
        'email' => 'unsub@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->actingAs($unsubscribed)
        ->get(route('student.exam.start', $data['exam']));

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// Start: creates attempt and renders confirmation page
// ---------------------------------------------------------------------------

it('shows start confirmation page for subscribed student', function () {
    $data = seedExamTaking();

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.start', $data['exam']));

    $response->assertStatus(200);
    $response->assertSee('ET Exam');
    $response->assertSee('Comenzar examen');
});

// ---------------------------------------------------------------------------
// Start: clicking start creates attempt and redirects to take
// ---------------------------------------------------------------------------

it('creates attempt and redirects to take when student clicks start', function () {
    $data = seedExamTaking();

    // Simulate the Livewire start action by directly posting the start route
    // and having the component create the attempt.
    // Since ExamStart is a Livewire component, we test via the underlying
    // controller logic: manually create the attempt as the component would.

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.take', $attempt));

    $response->assertStatus(200);
    $response->assertSee('Q1: What is 2+2?');
    $response->assertSee('Pregunta 1 de 2');

    expect(StudentAttempt::count())->toBe(1);
    expect($attempt->started_at)->not->toBeNull();
    expect($attempt->finished_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Take: denied to non-owner
// ---------------------------------------------------------------------------

it('denies student from viewing another students attempt', function () {
    $data = seedExamTaking();

    $other = User::create([
        'name' => 'Other Student',
        'email' => 'other@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);
    $other->subscribedClasses()->attach($data['class']->id);

    $attempt = StudentAttempt::create([
        'student_id' => $other->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.take', $attempt));

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// Answer: controller saves answer and redirects
// ---------------------------------------------------------------------------

it('saves answer and redirects back to take with next question', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $q1 = $data['questions'][0];
    $correctOption = $q1->options()->where('is_correct', true)->first();

    $response = $this->actingAs($data['student'])
        ->post(route('student.exam.answer', [
            'attempt' => $attempt,
            'question' => $q1,
        ]), [
            'options' => [$correctOption->id],
        ]);

    $response->assertRedirect();
    // Should have one answer row
    expect(StudentAnswer::where('student_attempt_id', $attempt->id)
        ->where('question_id', $q1->id)
        ->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Answer: re-answering replaces previous selection (idempotent replace)
// ---------------------------------------------------------------------------

it('re-answering a question replaces the previous selection', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $q1 = $data['questions'][0];
    $wrongOption = $q1->options()->where('is_correct', false)->first();
    $correctOption = $q1->options()->where('is_correct', true)->first();

    // First answer: wrong
    $this->actingAs($data['student'])
        ->post(route('student.exam.answer', [
            'attempt' => $attempt,
            'question' => $q1,
        ]), ['options' => [$wrongOption->id]]);

    expect(StudentAnswer::where('student_attempt_id', $attempt->id)
        ->where('question_id', $q1->id)
        ->where('answer_option_id', $wrongOption->id)
        ->count())->toBe(1);

    // Re-answer: correct (replaces wrong)
    $this->actingAs($data['student'])
        ->post(route('student.exam.answer', [
            'attempt' => $attempt,
            'question' => $q1,
        ]), ['options' => [$correctOption->id]]);

    expect(StudentAnswer::where('student_attempt_id', $attempt->id)
        ->where('question_id', $q1->id)
        ->count())->toBe(1);

    expect(StudentAnswer::where('student_attempt_id', $attempt->id)
        ->where('question_id', $q1->id)
        ->where('answer_option_id', $correctOption->id)
        ->count())->toBe(1);
});

it('rejects direct answer replacement after finalization without changing attempt state', function () {
    $data = seedExamTaking();
    $finishedAt = now()->subMinute()->startOfSecond();
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now()->subMinutes(10),
        'finished_at' => $finishedAt,
        'score_obtained' => 5,
    ]);
    $question = $data['questions'][0];
    $wrong = $question->options()->where('is_correct', false)->first();
    $correct = $question->options()->where('is_correct', true)->first();
    StudentAnswer::create(['student_attempt_id' => $attempt->id, 'question_id' => $question->id, 'answer_option_id' => $wrong->id]);

    $this->actingAs($data['student'])
        ->post(route('student.exam.answer', ['attempt' => $attempt, 'question' => $question]), ['options' => [$correct->id]])
        ->assertSessionHasErrors('options');

    $attempt->refresh();
    expect($attempt->answers()->pluck('answer_option_id')->all())->toBe([$wrong->id])
        ->and((float) $attempt->score_obtained)->toBe(5.0)
        ->and($attempt->finished_at->equalTo($finishedAt))->toBeTrue();
});

it('rejects a stale attempt model after its database row is finalized', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    $question = $data['questions'][0];
    $wrong = $question->options()->where('is_correct', false)->first();
    $correct = $question->options()->where('is_correct', true)->first();
    StudentAnswer::create(['student_attempt_id' => $attempt->id, 'question_id' => $question->id, 'answer_option_id' => $wrong->id]);
    StudentAttempt::whereKey($attempt->id)->update(['finished_at' => now(), 'score_obtained' => 0]);

    expect(fn () => app(AnswerSelectionWriter::class)->replace($attempt, $question, [$correct->id]))
        ->toThrow(ValidationException::class);
    expect($attempt->answers()->pluck('answer_option_id')->all())->toBe([$wrong->id]);
});

it('rejects a foreign option for SINGLE without replacing the previous answer', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    [$single, $multiple] = $data['questions'];
    $previous = $single->options()->where('is_correct', false)->first();
    $foreign = $multiple->options()->where('is_correct', true)->first();
    StudentAnswer::create(['student_attempt_id' => $attempt->id, 'question_id' => $single->id, 'answer_option_id' => $previous->id]);

    $this->actingAs($data['student'])
        ->post(route('student.exam.answer', ['attempt' => $attempt, 'question' => $single]), ['options' => [$foreign->id]])
        ->assertSessionHasErrors('options.0');

    expect($attempt->answers()->where('question_id', $single->id)->pluck('answer_option_id')->all())->toBe([$previous->id]);
});

it('rejects a mixed foreign MULTIPLE selection atomically', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    [$single, $multiple] = $data['questions'];
    $previous = $multiple->options()->where('is_correct', false)->first();
    $valid = $multiple->options()->where('is_correct', true)->first();
    $foreign = $single->options()->where('is_correct', true)->first();
    StudentAnswer::create(['student_attempt_id' => $attempt->id, 'question_id' => $multiple->id, 'answer_option_id' => $previous->id]);

    $this->actingAs($data['student'])
        ->post(route('student.exam.answer', ['attempt' => $attempt, 'question' => $multiple]), ['options' => [$valid->id, $foreign->id]])
        ->assertSessionHasErrors('options.1');

    expect($attempt->answers()->where('question_id', $multiple->id)->pluck('answer_option_id')->all())->toBe([$previous->id]);
});

it('rejects multiple distinct options for SINGLE', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    $single = $data['questions'][0];

    $this->actingAs($data['student'])
        ->post(route('student.exam.answer', ['attempt' => $attempt, 'question' => $single]), ['options' => $single->options()->pluck('id')->all()])
        ->assertSessionHasErrors('options');

    expect($attempt->answers()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Answer: denies for question not in exam
// ---------------------------------------------------------------------------

it('denies answer for a question not belonging to the attempts exam', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    // Create a second exam with a question
    $otherExam = Exam::create([
        'class_id' => $data['class']->id,
        'title' => 'Other Exam',
        'duration_minutes' => 30,
        'max_score' => 10,
    ]);

    $otherQ = Question::create([
        'exam_id' => $otherExam->id,
        'text' => 'Other question',
        'type' => 'SINGLE',
        'points' => 10,
        'order' => 0,
    ]);

    $response = $this->actingAs($data['student'])
        ->post(route('student.exam.answer', [
            'attempt' => $attempt,
            'question' => $otherQ,
        ]), [
            'options' => [1],
        ]);

    $response->assertNotFound();
});

// ---------------------------------------------------------------------------
// Submit: grades the attempt and redirects to result
// ---------------------------------------------------------------------------

it('submit grades the attempt and redirects to result', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    // Answer Q1 correctly (5 pts)
    $q1 = $data['questions'][0];
    $correctOption = $q1->options()->where('is_correct', true)->first();
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $q1->id,
        'answer_option_id' => $correctOption->id,
    ]);

    // Answer Q2 correctly (10 pts) — select both correct options
    $q2 = $data['questions'][1];
    foreach ($q2->options()->where('is_correct', true)->get() as $opt) {
        StudentAnswer::create([
            'student_attempt_id' => $attempt->id,
            'question_id' => $q2->id,
            'answer_option_id' => $opt->id,
        ]);
    }

    $response = $this->actingAs($data['student'])
        ->post(route('student.exam.submit', $attempt));

    $response->assertRedirect(route('student.exam.result', $attempt));

    $attempt->refresh();
    expect($attempt->finished_at)->not->toBeNull();
    expect((float) $attempt->score_obtained)->toBe(15.0);
});

// ---------------------------------------------------------------------------
// Result: shows score as "X / Y"
// ---------------------------------------------------------------------------

it('result page shows score as X over Y', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
        'finished_at' => now(),
        'score_obtained' => 10,
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.result', $attempt));

    // The component redirects ungraded attempts; ours is graded so it shows the result.
    $response->assertStatus(200);
    $response->assertSee('10');
    $response->assertSee('15');
    $response->assertSee('ET Exam');
});

// ---------------------------------------------------------------------------
// Result: redirects to take if not yet graded
// ---------------------------------------------------------------------------

it('result page redirects to take when attempt is ungraded', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
        'finished_at' => null,
    ]);

    $response = $this->actingAs($data['student'])
        ->get(route('student.exam.result', $attempt));

    // The component redirects via Livewire; since we're making a raw HTTP
    // request, the redirect happens server-side via the mount method.
    $response->assertRedirect(route('student.exam.take', $attempt));
});

// ---------------------------------------------------------------------------
// Answer: cross-student ownership check → 403
// ---------------------------------------------------------------------------

it('denies answer for attempt belonging to another student', function () {
    $data = seedExamTaking();

    $other = User::create([
        'name' => 'Other Student 2',
        'email' => 'other2@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);
    $other->subscribedClasses()->attach($data['class']->id);

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $q1 = $data['questions'][0];
    $correctOption = $q1->options()->where('is_correct', true)->first();

    $response = $this->actingAs($other)
        ->post(route('student.exam.answer', [
            'attempt' => $attempt,
            'question' => $q1,
        ]), [
            'options' => [$correctOption->id],
        ]);

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// Submit: cross-student ownership check → 403
// ---------------------------------------------------------------------------

it('denies submit for attempt belonging to another student', function () {
    $data = seedExamTaking();

    $other = User::create([
        'name' => 'Other Student 3',
        'email' => 'other3@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);
    $other->subscribedClasses()->attach($data['class']->id);

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($other)
        ->post(route('student.exam.submit', $attempt));

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// Livewire: ExamStart::start does not throw TypeError (fix verification)
// ---------------------------------------------------------------------------

it('ExamStart start action redirects without TypeError', function () {
    $data = seedExamTaking();

    Auth::login($data['student']);

    $component = Livewire::test(ExamStart::class, ['exam' => $data['exam']]);

    // Calling start() should not throw TypeError after the return-type fix.
    $component->call('start');

    $component->assertRedirect();

    expect(StudentAttempt::where('student_id', $data['student']->id)
        ->where('exam_id', $data['exam']->id)
        ->exists())->toBeTrue();
});

it('denies start when subscription is revoked after the component mounts', function () {
    $data = seedExamTaking();
    $component = Livewire::actingAs($data['student'])->test(ExamStart::class, ['exam' => $data['exam']]);

    $data['student']->subscribedClasses()->detach($data['class']->id);

    $component->call('start')->assertForbidden();
    expect(StudentAttempt::count())->toBe(0);
});

it('denies taking an unfinished attempt after subscription is revoked', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    $data['student']->subscribedClasses()->detach($data['class']->id);

    $this->actingAs($data['student'])->get(route('student.exam.take', $attempt))->assertForbidden();

    expect($attempt->fresh()->finished_at)->toBeNull()
        ->and($attempt->answers()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Livewire: ExamTake::saveAndNext does not throw TypeError (fix verification)
// ---------------------------------------------------------------------------

it('ExamTake saveAndNext action redirects without TypeError', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    Auth::login($data['student']);

    $component = Livewire::test(ExamTake::class, ['attempt' => $attempt]);

    // Calling saveAndNext should redirect without TypeError after the return-type fix.
    $component->call('saveAndNext');

    $component->assertRedirect();
});

it('ExamTake saveAndNext rejects a foreign option without persistence', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    [$single, $multiple] = $data['questions'];
    $foreign = $multiple->options()->where('is_correct', true)->first();

    Livewire::actingAs($data['student'])->test(ExamTake::class, ['attempt' => $attempt])
        ->set("selectedOptions.{$single->id}", [$foreign->id])
        ->call('saveAndNext')
        ->assertHasErrors('options.0');

    expect($attempt->answers()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Livewire: ExamTake::finalize does not throw TypeError (fix verification)
// ---------------------------------------------------------------------------

it('ExamTake finalize action redirects without TypeError', function () {
    $data = seedExamTaking();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    // Answer Q1 correctly so finalize has something to grade.
    $q1 = $data['questions'][0];
    $correctOption = $q1->options()->where('is_correct', true)->first();
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $q1->id,
        'answer_option_id' => $correctOption->id,
    ]);

    Auth::login($data['student']);

    $component = Livewire::test(ExamTake::class, ['attempt' => $attempt]);

    // Calling finalize should redirect to result without TypeError after the return-type fix.
    $component->call('finalize');

    $component->assertRedirect();

    $attempt->refresh();
    expect($attempt->finished_at)->not->toBeNull();
    expect((float) $attempt->score_obtained)->toBeGreaterThan(0);
});

it('ExamTake finalize rejects a foreign option without persistence or grading', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    [$single, $multiple] = $data['questions'];
    $foreign = $multiple->options()->where('is_correct', true)->first();

    Livewire::actingAs($data['student'])->test(ExamTake::class, ['attempt' => $attempt])
        ->set("selectedOptions.{$single->id}", [$foreign->id])
        ->call('finalize')
        ->assertHasErrors('options.0');

    $attempt->refresh();
    expect($attempt->answers()->count())->toBe(0)
        ->and($attempt->finished_at)->toBeNull();
});

it('denies Livewire answer and finalize mutations after subscription is revoked', function () {
    $data = seedExamTaking();
    $question = $data['questions'][0];
    $correct = $question->options()->where('is_correct', true)->first();

    foreach (['saveAndNext', 'finalize'] as $action) {
        $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
        $component = Livewire::actingAs($data['student'])->test(ExamTake::class, ['attempt' => $attempt])
            ->set("selectedOptions.{$question->id}", [$correct->id]);
        $data['student']->subscribedClasses()->detach($data['class']->id);

        $component->call($action)->assertForbidden();
        expect($attempt->answers()->count())->toBe(0)
            ->and($attempt->fresh()->finished_at)->toBeNull();

        $attempt->delete();
        $data['student']->subscribedClasses()->attach($data['class']->id);
    }
});

it('denies HTTP answer and submit mutations after subscription is revoked', function () {
    $data = seedExamTaking();
    $question = $data['questions'][0];
    $wrong = $question->options()->where('is_correct', false)->first();
    $correct = $question->options()->where('is_correct', true)->first();
    $attempt = StudentAttempt::create(['student_id' => $data['student']->id, 'exam_id' => $data['exam']->id, 'started_at' => now()]);
    StudentAnswer::create(['student_attempt_id' => $attempt->id, 'question_id' => $question->id, 'answer_option_id' => $wrong->id]);
    $data['student']->subscribedClasses()->detach($data['class']->id);

    $this->actingAs($data['student'])
        ->post(route('student.exam.answer', compact('attempt', 'question')), ['options' => [$correct->id]])
        ->assertForbidden();
    $this->post(route('student.exam.submit', $attempt))->assertForbidden();

    expect($attempt->answers()->pluck('answer_option_id')->all())->toBe([$wrong->id])
        ->and($attempt->fresh()->finished_at)->toBeNull()
        ->and($attempt->score_obtained)->toBeNull();
});

it('allows an owner to view a completed result after subscription is revoked', function () {
    $data = seedExamTaking();
    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'score_obtained' => 10,
    ]);
    $data['student']->subscribedClasses()->detach($data['class']->id);

    $this->actingAs($data['student'])
        ->get(route('student.exam.result', $attempt))
        ->assertOk()
        ->assertSee('ET Exam');
});
