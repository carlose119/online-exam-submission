<?php

use App\Models\Question;
use App\Models\StudentAttempt;
use App\Services\AnswerSelectionWriter;
use App\Services\ExamGradingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ipcPath = $argv[2] ?? throw new InvalidArgumentException('Worker IPC path is required.');
$write = static function (array $message) use ($ipcPath): void {
    file_put_contents($ipcPath, json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
};

try {
    if (DB::getDatabaseName() !== 'online_exam_submission_concurrency') {
        throw new RuntimeException('Refusing to run against a non-disposable database.');
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    $connectionId = (int) DB::scalar('SELECT CONNECTION_ID()');
    $write([
        'event' => 'ready',
        'connection_id' => $connectionId,
        'database' => DB::getDatabaseName(),
    ]);

    $operation = $argv[1] ?? null;
    $attempt = StudentAttempt::query()->findOrFail((int) ($argv[3] ?? 0));

    if ($operation === 'grade') {
        $score = app(ExamGradingService::class)->gradeAttempt($attempt);
        $write(['event' => 'result', 'status' => 'graded', 'score' => $score]);
    } elseif ($operation === 'replace') {
        $question = Question::query()->findOrFail((int) ($argv[4] ?? 0));
        app(AnswerSelectionWriter::class)->replace($attempt, $question, [(int) ($argv[5] ?? 0)]);
        $write(['event' => 'result', 'status' => 'replaced']);
    } else {
        throw new InvalidArgumentException('Unknown concurrency worker operation.');
    }
} catch (ValidationException $exception) {
    $write(['event' => 'result', 'status' => 'validation_exception', 'errors' => $exception->errors()]);
} catch (Throwable $exception) {
    $write(['event' => 'result', 'status' => 'error', 'message' => $exception->getMessage()]);
    exit(1);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect();
}
