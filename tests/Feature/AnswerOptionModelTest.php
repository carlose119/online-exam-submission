<?php

use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// (a) AnswerOption belongs to Question
// ---------------------------------------------------------------------------

it('answer option belongs to question', function () {
    $teacher = User::create([
        'name' => 'OptRel Teacher',
        'email' => 'optrel@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'OptRel Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'OPTREL01',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Option Relations Exam',
        'duration_minutes' => 30,
        'max_score' => 50,
    ]);

    $question = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Which is correct?',
        'type' => 'SINGLE',
        'points' => 10,
        'order' => 0,
    ]);

    $option = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'The right answer',
        'is_correct' => true,
    ]);

    expect($option->question)->not->toBeNull();
    expect($option->question->id)->toBe($question->id);
    expect($option->question)->toBeInstanceOf(Question::class);
    expect($option->question->text)->toBe('Which is correct?');
});

// ---------------------------------------------------------------------------
// (b) is_correct boolean cast returns PHP booleans
// ---------------------------------------------------------------------------

it('is_correct cast returns PHP boolean', function () {
    $teacher = User::create([
        'name' => 'BoolOpt Teacher',
        'email' => 'boolopt@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'BoolOpt Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'BOOLOPT1',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'BoolOpt Exam',
        'duration_minutes' => 20,
        'max_score' => 20,
    ]);

    $question = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Boolean test',
        'type' => 'SINGLE',
        'points' => 5,
        'order' => 0,
    ]);

    $correct = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Correct',
        'is_correct' => true,
    ]);

    $incorrect = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Incorrect',
        'is_correct' => false,
    ]);

    $freshCorrect = AnswerOption::find($correct->id);
    $freshIncorrect = AnswerOption::find($incorrect->id);

    expect($freshCorrect->is_correct)->toBeTrue();
    expect(is_bool($freshCorrect->is_correct))->toBeTrue();

    expect($freshIncorrect->is_correct)->toBeFalse();
    expect(is_bool($freshIncorrect->is_correct))->toBeTrue();
});

// ---------------------------------------------------------------------------
// (c) is_correct = true persists as integer 1 in the database
// ---------------------------------------------------------------------------

it('is_correct true persists as integer 1 in the database', function () {
    $teacher = User::create([
        'name' => 'DBOpt Teacher',
        'email' => 'dbopt@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'DBOpt Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'DBOPTCLS',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'DB Persist Exam',
        'duration_minutes' => 30,
        'max_score' => 100,
    ]);

    $question = Question::create([
        'exam_id' => $exam->id,
        'text' => 'DB test',
        'type' => 'SINGLE',
        'points' => 5,
        'order' => 0,
    ]);

    $option = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Persisted true',
        'is_correct' => true,
    ]);

    // Check the raw database value
    $raw = DB::table('answer_options')->where('id', $option->id)->first();

    expect($raw->is_correct)->toBe(1);
    expect(is_int($raw->is_correct))->toBeTrue();
});
