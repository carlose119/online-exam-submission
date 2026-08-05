<?php

use App\Enums\QuestionType;
use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ExamGradingService;

// ---------------------------------------------------------------------------
// Helper: create a complete exam setup with teacher, class, exam, questions, and options
// Returns [teacher, class, exam, questions]
// ---------------------------------------------------------------------------

function createExamWithQuestions(User $teacher, SchoolClass $class, array $questionDefs): Exam
{
    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Test Exam',
        'duration_minutes' => 60,
        'max_score' => collect($questionDefs)->sum('points'),
    ]);

    foreach ($questionDefs as $index => $qDef) {
        $question = Question::create([
            'exam_id' => $exam->id,
            'text' => $qDef['text'] ?? "Question {$index}",
            'type' => $qDef['type'],
            'points' => $qDef['points'],
            'order' => $index,
        ]);

        foreach ($qDef['options'] as $optDef) {
            AnswerOption::create([
                'question_id' => $question->id,
                'text' => $optDef['text'],
                'is_correct' => $optDef['is_correct'],
            ]);
        }
    }

    return $exam;
}

// ---------------------------------------------------------------------------
// Helper: create a seed setup with teacher, class, student, and exam
// ---------------------------------------------------------------------------

function seedGradingTest($questionDefs): array
{
    $teacher = User::create([
        'name' => 'Grading Teacher',
        'email' => 'grading.teacher@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Grading Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'GRDTEST1',
    ]);

    $student = User::create([
        'name' => 'Grading Student',
        'email' => 'grading.student@example.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $exam = createExamWithQuestions($teacher, $class, $questionDefs);

    return compact('teacher', 'class', 'student', 'exam');
}

// ---------------------------------------------------------------------------
// Helper: create a StudentAttempt with given student and exam
// ---------------------------------------------------------------------------

function createAttempt(User $student, Exam $exam): StudentAttempt
{
    return StudentAttempt::create([
        'student_id' => $student->id,
        'exam_id' => $exam->id,
        'started_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// SINGLE: Correct answer → full points
// ---------------------------------------------------------------------------

it('SINGLE question with correct answer awards full points', function () {
    $data = seedGradingTest([
        [
            'text' => 'What is 2+2?',
            'type' => 'SINGLE',
            'points' => 5,
            'options' => [
                ['text' => '3', 'is_correct' => false],
                ['text' => '4', 'is_correct' => true],
                ['text' => '5', 'is_correct' => false],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $question = $exam->questions()->first();
    $correctOption = $question->options()->where('is_correct', true)->first();

    $attempt = createAttempt($data['student'], $exam);

    // Student selected the correct option
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $question->id,
        'answer_option_id' => $correctOption->id,
    ]);

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(5.0);
});

// ---------------------------------------------------------------------------
// SINGLE: Incorrect answer → 0 points
// ---------------------------------------------------------------------------

it('SINGLE question with incorrect answer awards 0 points', function () {
    $data = seedGradingTest([
        [
            'text' => 'What is 2+2?',
            'type' => 'SINGLE',
            'points' => 5,
            'options' => [
                ['text' => '3', 'is_correct' => false],
                ['text' => '4', 'is_correct' => true],
                ['text' => '5', 'is_correct' => false],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $question = $exam->questions()->first();
    $incorrectOption = $question->options()->where('is_correct', false)->first();

    $attempt = createAttempt($data['student'], $exam);

    // Student selected an incorrect option
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $question->id,
        'answer_option_id' => $incorrectOption->id,
    ]);

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(0.0);
});

it('SINGLE question with a foreign correct option awards 0 points', function () {
    $data = seedGradingTest([
        ['text' => 'Target', 'type' => 'SINGLE', 'points' => 5, 'options' => [['text' => 'Wrong', 'is_correct' => false]]],
        ['text' => 'Other', 'type' => 'SINGLE', 'points' => 0, 'options' => [['text' => 'Foreign correct', 'is_correct' => true]]],
    ]);
    [$target, $other] = $data['exam']->questions()->orderBy('order')->get();
    $attempt = createAttempt($data['student'], $data['exam']);
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $target->id,
        'answer_option_id' => $other->options()->first()->id,
    ]);

    expect((new ExamGradingService())->gradeAttempt($attempt))->toBe(0.0);
});

// ---------------------------------------------------------------------------
// SINGLE: No answer (blank) → 0 points
// ---------------------------------------------------------------------------

it('SINGLE question with no answer awards 0 points', function () {
    $data = seedGradingTest([
        [
            'text' => 'What is 2+2?',
            'type' => 'SINGLE',
            'points' => 5,
            'options' => [
                ['text' => '3', 'is_correct' => false],
                ['text' => '4', 'is_correct' => true],
                ['text' => '5', 'is_correct' => false],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $attempt = createAttempt($data['student'], $exam);

    // Student left the question blank — no answer rows

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// MULTIPLE: All correct, no incorrect → full points
// ---------------------------------------------------------------------------

it('MULTIPLE question with all correct and no incorrect awards full points', function () {
    $data = seedGradingTest([
        [
            'text' => 'Select all prime numbers:',
            'type' => 'MULTIPLE',
            'points' => 5,
            'options' => [
                ['text' => '2', 'is_correct' => true],
                ['text' => '3', 'is_correct' => true],
                ['text' => '4', 'is_correct' => false],
                ['text' => '5', 'is_correct' => true],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $question = $exam->questions()->first();
    $correctOptions = $question->options()->where('is_correct', true)->get();

    $attempt = createAttempt($data['student'], $exam);

    // Student selected all correct options (2, 3, 5) and no incorrect ones
    foreach ($correctOptions as $option) {
        StudentAnswer::create([
            'student_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'answer_option_id' => $option->id,
        ]);
    }

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(5.0);
});

// ---------------------------------------------------------------------------
// MULTIPLE: All correct plus one incorrect → 0 points
// ---------------------------------------------------------------------------

it('MULTIPLE question with correct plus one incorrect awards 0 points', function () {
    $data = seedGradingTest([
        [
            'text' => 'Select all prime numbers:',
            'type' => 'MULTIPLE',
            'points' => 5,
            'options' => [
                ['text' => '2', 'is_correct' => true],
                ['text' => '3', 'is_correct' => true],
                ['text' => '4', 'is_correct' => false],
                ['text' => '5', 'is_correct' => true],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $question = $exam->questions()->first();
    $correctOptions = $question->options()->where('is_correct', true)->get();
    $incorrectOption = $question->options()->where('is_correct', false)->first(); // 4

    $attempt = createAttempt($data['student'], $exam);

    // Student selected all correct options AND the incorrect option 4
    foreach ($correctOptions as $option) {
        StudentAnswer::create([
            'student_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'answer_option_id' => $option->id,
        ]);
    }

    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $question->id,
        'answer_option_id' => $incorrectOption->id,
    ]);

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// MULTIPLE: Some correct but not all → 0 points
// ---------------------------------------------------------------------------

it('MULTIPLE question with some correct but not all awards 0 points', function () {
    $data = seedGradingTest([
        [
            'text' => 'Select all prime numbers:',
            'type' => 'MULTIPLE',
            'points' => 5,
            'options' => [
                ['text' => '2', 'is_correct' => true],
                ['text' => '3', 'is_correct' => true],
                ['text' => '4', 'is_correct' => false],
                ['text' => '5', 'is_correct' => true],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $question = $exam->questions()->first();
    // Only select the first correct option (2), missing 3 and 5
    $partialOption = $question->options()->where('text', '2')->first();

    $attempt = createAttempt($data['student'], $exam);

    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $question->id,
        'answer_option_id' => $partialOption->id,
    ]);

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// MULTIPLE: No options selected (blank) → 0 points
// ---------------------------------------------------------------------------

it('MULTIPLE question with no options selected awards 0 points', function () {
    $data = seedGradingTest([
        [
            'text' => 'Select all prime numbers:',
            'type' => 'MULTIPLE',
            'points' => 5,
            'options' => [
                ['text' => '2', 'is_correct' => true],
                ['text' => '3', 'is_correct' => true],
                ['text' => '4', 'is_correct' => false],
                ['text' => '5', 'is_correct' => true],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $attempt = createAttempt($data['student'], $exam);

    // Student left the question blank — no answer rows

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Total score: sum of correctly answered question points
// ---------------------------------------------------------------------------

it('total score is the sum of points for correctly answered questions', function () {
    $data = seedGradingTest([
        [
            'text' => 'Single Q1',
            'type' => 'SINGLE',
            'points' => 5,
            'options' => [
                ['text' => 'Wrong', 'is_correct' => false],
                ['text' => 'Right', 'is_correct' => true],
            ],
        ],
        [
            'text' => 'Single Q2',
            'type' => 'SINGLE',
            'points' => 5,
            'options' => [
                ['text' => 'Wrong', 'is_correct' => false],
                ['text' => 'Right', 'is_correct' => true],
            ],
        ],
        [
            'text' => 'Single Q3',
            'type' => 'SINGLE',
            'points' => 5,
            'options' => [
                ['text' => 'Wrong', 'is_correct' => false],
                ['text' => 'Right', 'is_correct' => true],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $questions = $exam->questions()->orderBy('order')->get();
    [$q1, $q2, $q3] = [$questions[0], $questions[1], $questions[2]];

    $correctQ1 = $q1->options()->where('is_correct', true)->first();
    $correctQ2 = $q2->options()->where('is_correct', true)->first();
    // Q3: leave blank (0 points)

    $attempt = createAttempt($data['student'], $exam);

    // Q1: correct (5pts), Q2: correct (5pts), Q3: blank (0pts) → total = 10
    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $q1->id,
        'answer_option_id' => $correctQ1->id,
    ]);

    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $q2->id,
        'answer_option_id' => $correctQ2->id,
    ]);

    $service = new ExamGradingService();
    $score = $service->gradeAttempt($attempt);

    expect($score)->toBe(10.0);
});

// ---------------------------------------------------------------------------
// Idempotency: calling gradeAttempt twice returns the same score
// ---------------------------------------------------------------------------

it('gradeAttempt is idempotent and returns the same score on second call', function () {
    $data = seedGradingTest([
        [
            'text' => 'What is 2+2?',
            'type' => 'SINGLE',
            'points' => 5,
            'options' => [
                ['text' => '3', 'is_correct' => false],
                ['text' => '4', 'is_correct' => true],
                ['text' => '5', 'is_correct' => false],
            ],
        ],
    ]);

    $exam = $data['exam'];
    $question = $exam->questions()->first();
    $correctOption = $question->options()->where('is_correct', true)->first();

    $attempt = createAttempt($data['student'], $exam);

    StudentAnswer::create([
        'student_attempt_id' => $attempt->id,
        'question_id' => $question->id,
        'answer_option_id' => $correctOption->id,
    ]);

    $service = new ExamGradingService();

    // First call: should compute and store score
    $score1 = $service->gradeAttempt($attempt);
    $finishedAt1 = $attempt->fresh()->finished_at;
    $attempt->refresh();

    // Second call: should be a no-op, return same score
    $score2 = $service->gradeAttempt($attempt);
    $finishedAt2 = $attempt->fresh()->finished_at;

    expect($score1)->toBe(5.0);
    expect($score2)->toBe(5.0);
    // finished_at should not change on re-grade
    expect($finishedAt2->timestamp)->toEqual($finishedAt1->timestamp);
});
