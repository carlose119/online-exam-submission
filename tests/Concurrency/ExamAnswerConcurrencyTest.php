<?php

use App\Models\AnswerOption;
use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\AnswerSelectionWriter;
use App\Services\ExamGradingService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExamConcurrencyHarness;

function concurrencyFixture(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $student = User::factory()->create(['role' => 'STUDENT']);
    $class = SchoolClass::create([
        'teacher_id' => $teacher->id, 'title' => 'Concurrency', 'invitation_code' => 'CONCUR123456',
    ]);
    $exam = Exam::create([
        'class_id' => $class->id, 'title' => 'Concurrency', 'duration_minutes' => 60, 'max_score' => 5,
    ]);
    $question = Question::create([
        'exam_id' => $exam->id, 'text' => 'Concurrent answer', 'type' => 'SINGLE', 'points' => 5, 'order' => 0,
    ]);
    $incorrect = AnswerOption::create(['question_id' => $question->id, 'text' => 'Incorrect', 'is_correct' => false]);
    $correct = AnswerOption::create(['question_id' => $question->id, 'text' => 'Correct', 'is_correct' => true]);
    $attempt = StudentAttempt::create(['student_id' => $student->id, 'exam_id' => $exam->id, 'started_at' => now()]);

    return compact('question', 'incorrect', 'correct', 'attempt');
}

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql')
        ->and(DB::getDatabaseName())->toBe('online_exam_submission_concurrency');
});

it('grades the replacement committed by the lock holder', function () {
    $data = concurrencyFixture();
    StudentAnswer::create([
        'student_attempt_id' => $data['attempt']->id,
        'question_id' => $data['question']->id,
        'answer_option_id' => $data['incorrect']->id,
    ]);
    $worker = null;
    DB::beginTransaction();
    try {
        app(AnswerSelectionWriter::class)->replace($data['attempt'], $data['question'], [$data['correct']->id]);
        $parentConnectionId = (int) DB::scalar('SELECT CONNECTION_ID()');
        $parentTransactionId = (int) DB::scalar(
            "SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = {$parentConnectionId}",
        );
        $worker = ExamConcurrencyHarness::start('grade', $data['attempt']->id);
        $ready = ExamConcurrencyHarness::message($worker);
        expect($ready)->toMatchArray([
            'event' => 'ready', 'database' => 'online_exam_submission_concurrency',
        ]);
        $wait = ExamConcurrencyHarness::observeLockWait($worker, $ready['connection_id']);
        expect((int) $wait['waiting_connection_id'])->toBe($ready['connection_id'])
            ->and((int) $wait['blocking_trx_id'])->toBe($parentTransactionId);
        DB::commit();
        $result = ExamConcurrencyHarness::message($worker);
        expect($result)->toMatchArray(['event' => 'result', 'status' => 'graded', 'score' => 5]);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ExamConcurrencyHarness::stop($worker);
    }

    expect($data['attempt']->fresh()->score_obtained)->toBe('5.00')
        ->and($data['attempt']->fresh()->finished_at)->not->toBeNull()
        ->and($data['attempt']->answers()->sole()->answer_option_id)->toBe($data['correct']->id);
});

it('rejects replacement after grading commits without changing answers', function () {
    $data = concurrencyFixture();
    StudentAnswer::create([
        'student_attempt_id' => $data['attempt']->id,
        'question_id' => $data['question']->id,
        'answer_option_id' => $data['correct']->id,
    ]);
    $worker = null;
    DB::beginTransaction();
    try {
        expect(app(ExamGradingService::class)->gradeAttempt($data['attempt']))->toBe(5.0);
        $parentConnectionId = (int) DB::scalar('SELECT CONNECTION_ID()');
        $parentTransactionId = (int) DB::scalar(
            "SELECT trx_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = {$parentConnectionId}",
        );
        $worker = ExamConcurrencyHarness::start('replace', $data['attempt']->id, $data['question']->id, $data['incorrect']->id);
        $ready = ExamConcurrencyHarness::message($worker);
        expect($ready)->toMatchArray([
            'event' => 'ready', 'database' => 'online_exam_submission_concurrency',
        ]);
        $wait = ExamConcurrencyHarness::observeLockWait($worker, $ready['connection_id']);
        expect((int) $wait['waiting_connection_id'])->toBe($ready['connection_id'])
            ->and((int) $wait['blocking_trx_id'])->toBe($parentTransactionId);
        DB::commit();
        $result = ExamConcurrencyHarness::message($worker);
        expect($result['status'])->toBe('validation_exception')
            ->and($result['errors']['options'])->toContain('Answers cannot be changed after the exam attempt is finished.');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ExamConcurrencyHarness::stop($worker);
    }

    expect($data['attempt']->fresh()->score_obtained)->toBe('5.00')
        ->and($data['attempt']->answers()->sole()->answer_option_id)->toBe($data['correct']->id);
});
