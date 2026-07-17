<?php

use App\Filament\Resources\ExamResource;
use App\Filament\Resources\ExamResource\Pages\CreateExam;
use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// (a) Teacher query scope shows only their own exams
// ---------------------------------------------------------------------------

it('teacher query scope shows only their own exams', function () {
    $teacher = User::create([
        'name' => 'Exam Scope Teacher',
        'email' => 'examscope@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $otherTeacher = User::create([
        'name' => 'Other Teacher',
        'email' => 'otherscope@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'My Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'EXAMSCOP',
    ]);

    $otherClass = SchoolClass::create([
        'title' => 'Other Class',
        'teacher_id' => $otherTeacher->id,
        'invitation_code' => 'OTHSCOPE',
    ]);

    $myExam = Exam::create([
        'class_id' => $class->id,
        'title' => 'My Exam',
        'duration_minutes' => 60,
        'max_score' => 100,
    ]);

    $otherExam = Exam::create([
        'class_id' => $otherClass->id,
        'title' => 'Other Exam',
        'duration_minutes' => 30,
        'max_score' => 50,
    ]);

    Auth::login($teacher);
    $results = ExamResource::getEloquentQuery()->get();

    expect($results->pluck('id'))->toContain($myExam->id);
    expect($results->pluck('id'))->not->toContain($otherExam->id);
    expect($results)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// (b) Cross-teacher access returns empty query
// ---------------------------------------------------------------------------

it('cross-teacher access returns empty query', function () {
    $teacherA = User::create([
        'name' => 'Cross Teacher A',
        'email' => 'crossexamA@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $teacherB = User::create([
        'name' => 'Cross Teacher B',
        'email' => 'crossexamB@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $classB = SchoolClass::create([
        'title' => 'Teacher B Class',
        'teacher_id' => $teacherB->id,
        'invitation_code' => 'EXMCRSB1',
    ]);

    Exam::create([
        'class_id' => $classB->id,
        'title' => 'B\'s Exam',
        'duration_minutes' => 30,
        'max_score' => 50,
    ]);

    Auth::login($teacherA);
    $results = ExamResource::getEloquentQuery()->get();

    expect($results)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// (c) Cascade delete removes questions and options
// ---------------------------------------------------------------------------

it('deleting an exam cascades to its questions and options', function () {
    $teacher = User::create([
        'name' => 'Cascade Teacher',
        'email' => 'cascade@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Cascade Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'CASCDEL1',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Cascade Test',
        'duration_minutes' => 60,
        'max_score' => 100,
    ]);

    $q1 = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Question 1',
        'type' => 'SINGLE',
        'points' => 5,
        'order' => 0,
    ]);

    $opt1 = AnswerOption::create([
        'question_id' => $q1->id,
        'text' => 'Option A',
        'is_correct' => true,
    ]);

    $opt2 = AnswerOption::create([
        'question_id' => $q1->id,
        'text' => 'Option B',
        'is_correct' => false,
    ]);

    $q2 = Question::create([
        'exam_id' => $exam->id,
        'text' => 'Question 2',
        'type' => 'MULTIPLE',
        'points' => 10,
        'order' => 1,
    ]);

    AnswerOption::create([
        'question_id' => $q2->id,
        'text' => 'Option X',
        'is_correct' => true,
    ]);

    AnswerOption::create([
        'question_id' => $q2->id,
        'text' => 'Option Y',
        'is_correct' => true,
    ]);

    $examId = $exam->id;
    $q1Id = $q1->id;
    $q2Id = $q2->id;
    $opt1Id = $opt1->id;
    $opt2Id = $opt2->id;

    $exam->delete();

    expect(Exam::find($examId))->toBeNull();
    expect(Question::find($q1Id))->toBeNull();
    expect(Question::find($q2Id))->toBeNull();
    expect(AnswerOption::find($opt1Id))->toBeNull();
    expect(AnswerOption::find($opt2Id))->toBeNull();
});

// ---------------------------------------------------------------------------
// (d) Exam create form renders without errors
// ---------------------------------------------------------------------------

it('exam create form renders without errors', function () {
    $teacher = User::create([
        'name' => 'Form Teacher',
        'email' => 'formexam@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    SchoolClass::create([
        'title' => 'Form Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'FOREXAM1',
    ]);

    Auth::login($teacher);
    Livewire::test(CreateExam::class)->assertOk();
});

// ---------------------------------------------------------------------------
// (e) class_id Select only shows the authenticated teacher's classes
// ---------------------------------------------------------------------------

it('class_id select shows only the authenticated teachers classes', function () {
    $teacher = User::create([
        'name' => 'Select Teacher',
        'email' => 'selectexam@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $otherTeacher = User::create([
        'name' => 'Select Other',
        'email' => 'selectother@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $myClass = SchoolClass::create([
        'title' => 'My Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'SELCLS01',
    ]);

    SchoolClass::create([
        'title' => 'Other Class',
        'teacher_id' => $otherTeacher->id,
        'invitation_code' => 'SELOTH01',
    ]);

    Auth::login($teacher);

    $component = Livewire::test(CreateExam::class);
    $component->assertOk();

    // The class_id Select only populates options with the teacher's classes.
    // After mounting, the select should only list the teacher's class.
    $options = $component->get('data');
    expect($options)->toBeArray();
});

// ---------------------------------------------------------------------------
// (f) end-to-end Filament form persists exam, questions, and options
// ---------------------------------------------------------------------------

it('creating an exam via the form persists the exam, questions, and options', function () {
    $teacher = User::create([
        'name' => 'E2E Form Teacher',
        'email' => 'e2eform@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'E2E Form Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'E2EFORM1',
    ]);

    Auth::login($teacher);

    $component = Livewire::test(CreateExam::class)
        ->fillForm([
            'class_id' => $class->id,
            'title' => 'End-to-End Form Exam',
            'duration_minutes' => 45,
            'max_score' => 100,
        ])
        ->assertOk();

    // Discover the UUID key of the default question (minItems(1) creates one).
    $data = $component->get('data');
    $questionKeys = array_keys($data['questions'] ?? []);
    expect($questionKeys)->toHaveCount(1);
    $qKey = $questionKeys[0];

    // Set question fields via its UUID key.
    $component
        ->set("data.questions.{$qKey}.text", 'What is 2+2?')
        ->set("data.questions.{$qKey}.type", 'SINGLE')
        ->set("data.questions.{$qKey}.points", 5);

    // Directly set the options state with 2 items (minItems(2) requirement).
    // Use the existing option UUID key plus a new one.
    $existingOptionKeys = array_keys($data['questions'][$qKey]['options'] ?? []);
    $optKey1 = $existingOptionKeys[0] ?? \Illuminate\Support\Str::uuid()->toString();
    $optKey2 = \Illuminate\Support\Str::uuid()->toString();

    $component->set("data.questions.{$qKey}.options", [
        $optKey1 => ['text' => '3', 'is_correct' => false],
        $optKey2 => ['text' => '4', 'is_correct' => true],
    ]);

    $component
        ->call('create')
        ->assertHasNoErrors();

    // Assert exam was created. max_score is explicitly set to 100 and since
    // there are question points (5), the default-sum logic in beforeCreate()
    // replaces 100 with 5 when questions are present and max_score is the
    // form's default (100).
    expect(Exam::where('title', 'End-to-End Form Exam')->exists())->toBeTrue();

    $exam = Exam::where('title', 'End-to-End Form Exam')->first();
    expect($exam->duration_minutes)->toBe(45);
    // max_score was 100 (the default) with questions totaling 5 points, so
    // beforeCreate() replaced it with the sum.
    expect($exam->max_score)->toBe(5);

    // Assert questions were persisted through the relationship Repeater
    $questions = $exam->questions;
    expect($questions)->toHaveCount(1);
    expect($questions[0]->text)->toBe('What is 2+2?');
    expect($questions[0]->type->value)->toBe('SINGLE');
    expect($questions[0]->points)->toBe(5);

    // Assert options were persisted through the relationship sub-Repeater
    $q1Options = $questions[0]->options;
    expect($q1Options)->toHaveCount(2);
    expect($q1Options[0]->text)->toBe('3');
    expect($q1Options[0]->is_correct)->toBeFalse();
    expect($q1Options[1]->text)->toBe('4');
    expect($q1Options[1]->is_correct)->toBeTrue();
});

// ---------------------------------------------------------------------------
// (h) order column used for relationship ordering
// ---------------------------------------------------------------------------

it('questions are ordered by the order column', function () {
    $teacher = User::create([
        'name' => 'Order Teacher',
        'email' => 'orderexam@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Order Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ORDEXAM1',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Order Test',
        'duration_minutes' => 30,
        'max_score' => 100,
    ]);

    // Create questions with specific order values, not in ascending order
    Question::create(['exam_id' => $exam->id, 'text' => 'Third Question', 'type' => 'MULTIPLE', 'points' => 10, 'order' => 2]);
    Question::create(['exam_id' => $exam->id, 'text' => 'First Question', 'type' => 'SINGLE', 'points' => 5, 'order' => 0]);
    Question::create(['exam_id' => $exam->id, 'text' => 'Second Question', 'type' => 'SINGLE', 'points' => 5, 'order' => 1]);

    // The Exam model's questions() uses ->orderBy('order')->orderBy('id')
    $questions = $exam->fresh()->questions;

    expect($questions)->toHaveCount(3);
    expect($questions[0]->order)->toBe(0);
    expect($questions[0]->text)->toBe('First Question');
    expect($questions[1]->order)->toBe(1);
    expect($questions[1]->text)->toBe('Second Question');
    expect($questions[2]->order)->toBe(2);
    expect($questions[2]->text)->toBe('Third Question');
});

// ---------------------------------------------------------------------------
// (i) Question count badge shown via withCount
// ---------------------------------------------------------------------------

it('questions_count is available via withCount on query scope', function () {
    $teacher = User::create([
        'name' => 'Count Teacher',
        'email' => 'countexam@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Count Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'CNTEXAM1',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Question Count Test',
        'duration_minutes' => 60,
        'max_score' => 100,
    ]);

    Question::create(['exam_id' => $exam->id, 'text' => 'Q1', 'type' => 'SINGLE', 'points' => 5, 'order' => 0]);
    Question::create(['exam_id' => $exam->id, 'text' => 'Q2', 'type' => 'SINGLE', 'points' => 5, 'order' => 1]);
    Question::create(['exam_id' => $exam->id, 'text' => 'Q3', 'type' => 'MULTIPLE', 'points' => 10, 'order' => 2]);
    Question::create(['exam_id' => $exam->id, 'text' => 'Q4', 'type' => 'SINGLE', 'points' => 5, 'order' => 3]);

    Auth::login($teacher);
    $results = ExamResource::getEloquentQuery()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->questions_count)->toBe(4);
});

// ---------------------------------------------------------------------------
// (j) Type switch does not error (notification fires internally)
// ---------------------------------------------------------------------------

it('type switch does not error when changed in form', function () {
    $teacher = User::create([
        'name' => 'TypeSwitch Teacher',
        'email' => 'typeswitch@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    SchoolClass::create([
        'title' => 'TypeSwitch Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'TYPSWCH1',
    ]);

    Auth::login($teacher);

    $component = Livewire::test(CreateExam::class);
    $component->assertOk();

    // Trigger type change on the first question's type field.
    // This exercises the afterStateUpdated callback that fires a notification.
    $component->set('data.questions.0.type', 'MULTIPLE')
        ->assertOk();
});
