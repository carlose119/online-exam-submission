<?php

use App\Enums\QuestionType;
use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\User;
use App\Models\SchoolClass;

// ---------------------------------------------------------------------------
// (a) Question relationships resolve correctly
// ---------------------------------------------------------------------------

it('question belongs to exam and has many options', function () {
    $teacher = User::create([
        'name' => 'Rel Teacher',
        'email' => 'qrel@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Rel Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'QRELCLS1',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Relations Exam',
        'duration_minutes' => 60,
        'max_score' => 100,
    ]);

    $question = Question::create([
        'exam_id' => $exam->id,
        'text' => 'What is the answer?',
        'type' => 'SINGLE',
        'points' => 10,
        'order' => 0,
    ]);

    $opt1 = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Option A',
        'is_correct' => true,
    ]);

    $opt2 = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Option B',
        'is_correct' => false,
    ]);

    // Relationship: question → exam
    expect($question->exam)->not->toBeNull();
    expect($question->exam->id)->toBe($exam->id);
    expect($question->exam)->toBeInstanceOf(Exam::class);

    // Relationship: question → options
    expect($question->options)->toHaveCount(2);
    expect($question->options->first()->text)->toBe('Option A');
    expect($question->options->first())->toBeInstanceOf(AnswerOption::class);

    // Relationship: exam → questions (reverse)
    expect($exam->questions)->toHaveCount(1);
    expect($exam->questions->first()->id)->toBe($question->id);
});

// ---------------------------------------------------------------------------
// (b) QuestionType enum cast works
// ---------------------------------------------------------------------------

it('type field casts to QuestionType enum', function () {
    $teacher = User::create([
        'name' => 'Cast Teacher',
        'email' => 'qcast@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Cast Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'QCASTCL1',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Cast Exam',
        'duration_minutes' => 40,
        'max_score' => 50,
    ]);

    // Create with SINGLE string — should cast to QuestionType::Single
    $single = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Single choice question',
        'type' => 'SINGLE',
        'points' => 5,
        'order' => 0,
    ]);

    // Create with MULTIPLE string — should cast to QuestionType::Multiple
    $multiple = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Multiple choice question',
        'type' => 'MULTIPLE',
        'points' => 10,
        'order' => 1,
    ]);

    // Refresh from DB to ensure cast is applied
    $freshSingle = Question::find($single->id);
    $freshMultiple = Question::find($multiple->id);

    expect($freshSingle->type)->toBe(QuestionType::Single);
    expect($freshSingle->type)->toBeInstanceOf(QuestionType::class);
    expect($freshSingle->type->value)->toBe('SINGLE');
    expect($freshSingle->type->getLabel())->toBe('Single');

    expect($freshMultiple->type)->toBe(QuestionType::Multiple);
    expect($freshMultiple->type)->toBeInstanceOf(QuestionType::class);
    expect($freshMultiple->type->value)->toBe('MULTIPLE');
    expect($freshMultiple->type->getLabel())->toBe('Multiple');
});

// ---------------------------------------------------------------------------
// (c) AnswerOption is_correct boolean cast works
// ---------------------------------------------------------------------------

it('is_correct boolean cast works on answer options', function () {
    $teacher = User::create([
        'name' => 'BoolCast Teacher',
        'email' => 'boolcast@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'BoolCast Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'BOOLCAST',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'BoolCast Exam',
        'duration_minutes' => 30,
        'max_score' => 100,
    ]);

    $question = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Test question',
        'type' => 'SINGLE',
        'points' => 5,
        'order' => 0,
    ]);

    // is_correct as string '1' → PHP true
    $opt1 = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'String 1',
        'is_correct' => '1',
    ]);

    // is_correct as integer 1 → PHP true
    $opt2 = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Integer 1',
        'is_correct' => 1,
    ]);

    // is_correct as boolean true → PHP true
    $opt3 = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Boolean true',
        'is_correct' => true,
    ]);

    // is_correct as string '0' → PHP false
    $opt4 = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'String 0',
        'is_correct' => '0',
    ]);

    // is_correct as boolean false → PHP false
    $opt5 = AnswerOption::create([
        'question_id' => $question->id,
        'text' => 'Boolean false',
        'is_correct' => false,
    ]);

    $fresh1 = AnswerOption::find($opt1->id);
    $fresh2 = AnswerOption::find($opt2->id);
    $fresh3 = AnswerOption::find($opt3->id);
    $fresh4 = AnswerOption::find($opt4->id);
    $fresh5 = AnswerOption::find($opt5->id);

    expect($fresh1->is_correct)->toBeTrue();
    expect($fresh2->is_correct)->toBeTrue();
    expect($fresh3->is_correct)->toBeTrue();
    expect($fresh4->is_correct)->toBeFalse();
    expect($fresh5->is_correct)->toBeFalse();

    // Verify all true values are PHP boolean true
    expect(is_bool($fresh1->is_correct))->toBeTrue();
    expect(is_bool($fresh2->is_correct))->toBeTrue();
    expect(is_bool($fresh3->is_correct))->toBeTrue();
});
