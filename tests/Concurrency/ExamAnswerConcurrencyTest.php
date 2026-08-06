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

function startConcurrencyWorker(string $operation, int ...$ids): array
{
    $ipcPath = tempnam(sys_get_temp_dir(), 'exam-concurrency-');
    if ($ipcPath === false) {
        throw new RuntimeException('Unable to create worker IPC file.');
    }
    $command = [PHP_BINARY, base_path('tests/Support/ExamConcurrencyWorker.php'), $operation, $ipcPath, ...array_map('strval', $ids)];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
    if (! is_resource($process)) {
        @unlink($ipcPath);

        throw new RuntimeException('Unable to start concurrency worker.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes, 'ipc_path' => $ipcPath, 'ipc_offset' => 0, 'buffer' => ''];
}

function workerMessage(array &$worker, float $timeout = 10): array
{
    $deadline = microtime(true) + $timeout;
    do {
        $contents = @file_get_contents($worker['ipc_path']);
        if ($contents === false) {
            usleep(20_000);

            continue;
        }
        $worker['buffer'] .= substr($contents, $worker['ipc_offset']);
        $worker['ipc_offset'] = strlen($contents);
        if (($newline = strpos($worker['buffer'], "\n")) !== false) {
            $line = substr($worker['buffer'], 0, $newline);
            $worker['buffer'] = substr($worker['buffer'], $newline + 1);

            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Worker message deadline exceeded.');
}

function observeWorkerLockWait(array &$worker, int $workerConnectionId): array
{
    $config = config('database.connections.mysql');
    $monitor = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $deadline = microtime(true) + 10;
    do {
        if (! proc_get_status($worker['process'])['running']) {
            throw new RuntimeException(
                "Worker connection {$workerConnectionId} exited before waiting: ".json_encode(workerMessage($worker, 1)),
            );
        }
        $process = $monitor->query(
            "SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = {$workerConnectionId}",
        )->fetch(PDO::FETCH_ASSOC);
        if (str_contains(strtolower($process['INFO'] ?? ''), 'for update')) {
            $transaction = $monitor->query(
                "SELECT trx_id, trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = {$workerConnectionId}",
            )->fetch(PDO::FETCH_ASSOC);
            if (($transaction['trx_state'] ?? null) === 'LOCK WAIT') {
                $statement = $monitor->query(
                    "SELECT requesting_trx_id, blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS WHERE requesting_trx_id = {$transaction['trx_id']}",
                );
                $wait = $statement->fetch(PDO::FETCH_ASSOC);
                if ($wait !== false) {
                    return $wait + ['waiting_connection_id' => $workerConnectionId];
                }
            }
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("MariaDB lock-wait evidence missing for connection {$workerConnectionId}.");
}

function stopConcurrencyWorker(?array &$worker): void
{
    if ($worker === null) {
        return;
    }
    foreach ($worker['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($worker['process'])) {
        proc_terminate($worker['process']);
        proc_close($worker['process']);
    }
    @unlink($worker['ipc_path']);
    $worker = null;
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
        $worker = startConcurrencyWorker('grade', $data['attempt']->id);
        $ready = workerMessage($worker);
        expect($ready)->toMatchArray([
            'event' => 'ready', 'database' => 'online_exam_submission_concurrency',
        ]);
        $wait = observeWorkerLockWait($worker, $ready['connection_id']);
        expect((int) $wait['waiting_connection_id'])->toBe($ready['connection_id'])
            ->and((int) $wait['blocking_trx_id'])->toBe($parentTransactionId);
        DB::commit();
        $result = workerMessage($worker);
        expect($result)->toMatchArray(['event' => 'result', 'status' => 'graded', 'score' => 5]);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        stopConcurrencyWorker($worker);
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
        $worker = startConcurrencyWorker('replace', $data['attempt']->id, $data['question']->id, $data['incorrect']->id);
        $ready = workerMessage($worker);
        expect($ready)->toMatchArray([
            'event' => 'ready', 'database' => 'online_exam_submission_concurrency',
        ]);
        $wait = observeWorkerLockWait($worker, $ready['connection_id']);
        expect((int) $wait['waiting_connection_id'])->toBe($ready['connection_id'])
            ->and((int) $wait['blocking_trx_id'])->toBe($parentTransactionId);
        DB::commit();
        $result = workerMessage($worker);
        expect($result['status'])->toBe('validation_exception')
            ->and($result['errors']['options'])->toContain('Answers cannot be changed after the exam attempt is finished.');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        stopConcurrencyWorker($worker);
    }

    expect($data['attempt']->fresh()->score_obtained)->toBe('5.00')
        ->and($data['attempt']->answers()->sole()->answer_option_id)->toBe($data['correct']->id);
});
