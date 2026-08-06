<?php

use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function seedAttemptTest(): array
{
    $teacher = User::create([
        'name' => 'Attempt Teacher',
        'email' => 'attempt-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Attempt Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ATMPT001',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Attempt Test Exam',
        'duration_minutes' => 30,
        'max_score' => 10,
    ]);

    $question = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Test question?',
        'type' => 'SINGLE',
        'points' => 10,
        'order' => 0,
    ]);

    $correct = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Correct',
        'is_correct' => true,
    ]);

    $wrong = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Wrong',
        'is_correct' => false,
    ]);

    $student = User::create([
        'name' => 'Attempt Student',
        'email' => 'attempt-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student2 = User::create([
        'name' => 'Second Student',
        'email' => 'second-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    return compact('teacher', 'class', 'exam', 'question', 'correct', 'wrong', 'student', 'student2');
}

// ---------------------------------------------------------------------------
// Relationships
// ---------------------------------------------------------------------------

it('has correct relationships with student, exam, and answers', function () {
    $data = seedAttemptTest();
    $student = $data['student'];
    $exam = $data['exam'];
    $question = $data['question'];
    $correct = $data['correct'];

    $attempt = StudentAttempt::create([
        'student_id' => $student->id,
        'exam_id' => $exam->id,
        'started_at' => now(),
    ]);

    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $question->id,
        'answer_option_id' => $correct->id,
    ]);

    // StudentAttempt -> student
    expect($attempt->student)->toBeInstanceOf(User::class);
    expect($attempt->student->id)->toBe($student->id);

    // StudentAttempt -> exam
    expect($attempt->exam)->toBeInstanceOf(Exam::class);
    expect($attempt->exam->id)->toBe($exam->id);

    // StudentAttempt -> answers
    expect($attempt->answers)->toHaveCount(1);
    expect($attempt->answers->first())->toBeInstanceOf(StudentAnswer::class);
});

// ---------------------------------------------------------------------------
// UNIQUE constraint: (student_id, exam_id)
// ---------------------------------------------------------------------------

it('prevents duplicate attempts for the same student and exam', function () {
    $data = seedAttemptTest();

    StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    // Creating a duplicate should throw.
    StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);
})->throws(QueryException::class);

// ---------------------------------------------------------------------------
// UNIQUE constraint: different student, same exam → allowed
// ---------------------------------------------------------------------------

it('allows different students to attempt the same exam', function () {
    $data = seedAttemptTest();

    $a1 = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    $a2 = StudentAttempt::create([
        'student_id' => $data['student2']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    expect($a1->id)->not->toBe($a2->id);
    expect(StudentAttempt::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Cascade delete: deleting a student removes their attempts
// ---------------------------------------------------------------------------

it('cascade deletes attempts when student is deleted', function () {
    $data = seedAttemptTest();

    StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    expect(StudentAttempt::count())->toBe(1);

    $data['student']->delete();

    expect(StudentAttempt::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Cascade delete: deleting an exam removes its attempts
// ---------------------------------------------------------------------------

it('cascade deletes attempts when exam is deleted', function () {
    $data = seedAttemptTest();

    StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    expect(StudentAttempt::count())->toBe(1);

    $data['exam']->delete();

    expect(StudentAttempt::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// UNIQUE constraint on student_answers: (student_attempt_id, question_id, answer_option_id)
// ---------------------------------------------------------------------------

it('prevents duplicate answer rows for the same attempt, question, and option', function () {
    $data = seedAttemptTest();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $data['question']->id,
        'answer_option_id' => $data['correct']->id,
    ]);

    // Duplicate should throw.
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $data['question']->id,
        'answer_option_id' => $data['correct']->id,
    ]);
})->throws(QueryException::class);

// ---------------------------------------------------------------------------
// Date casts: started_at and finished_at are Carbon instances
// ---------------------------------------------------------------------------

it('casts started_at and finished_at as datetime', function () {
    $data = seedAttemptTest();

    $attempt = StudentAttempt::create([
        'student_id' => $data['student']->id,
        'exam_id' => $data['exam']->id,
        'started_at' => now(),
    ]);

    expect($attempt->started_at)->toBeInstanceOf(Carbon::class);
    expect($attempt->finished_at)->toBeNull();

    $attempt->update(['finished_at' => now()]);
    $attempt->refresh();

    expect($attempt->finished_at)->toBeInstanceOf(Carbon::class);
});
