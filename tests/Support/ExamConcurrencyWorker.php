<?php

use App\Models\Exam;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\AnswerSelectionWriter;
use App\Services\ExamAllowanceService;
use App\Services\ExamAttemptCreator;
use App\Services\ExamGradingService;
use App\Services\ReportArtifactPublisher;
use App\Services\ReportScheduleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

    if ($operation === 'grade') {
        $attempt = StudentAttempt::query()->findOrFail((int) ($argv[3] ?? 0));
        $score = app(ExamGradingService::class)->gradeAttempt($attempt);
        $write(['event' => 'result', 'status' => 'graded', 'score' => $score]);
    } elseif ($operation === 'replace') {
        $attempt = StudentAttempt::query()->findOrFail((int) ($argv[3] ?? 0));
        $question = Question::query()->findOrFail((int) ($argv[4] ?? 0));
        app(AnswerSelectionWriter::class)->replace($attempt, $question, [(int) ($argv[5] ?? 0)]);
        $write(['event' => 'result', 'status' => 'replaced']);
    } elseif ($operation === 'start') {
        $exam = Exam::query()->findOrFail((int) ($argv[3] ?? 0));
        $attempt = app(ExamAttemptCreator::class)->create($exam, (int) ($argv[4] ?? 0));
        $write([
            'event' => 'result', 'status' => 'started',
            'attempt_id' => $attempt->id, 'attempt_number' => $attempt->attempt_number,
        ]);
    } elseif ($operation === 'allowance') {
        $exam = Exam::query()->findOrFail((int) ($argv[3] ?? 0));
        $student = User::query()->findOrFail((int) ($argv[4] ?? 0));
        $allowance = app(ExamAllowanceService::class)->save($exam, $student, (int) ($argv[5] ?? 0), 0);
        $write(['event' => 'result', 'status' => 'allowance_saved', 'additional_attempts' => $allowance->additional_attempts]);
    } elseif ($operation === 'teacher-allowance') {
        $exam = Exam::query()->findOrFail((int) ($argv[3] ?? 0));
        $student = User::query()->findOrFail((int) ($argv[4] ?? 0));
        $teacher = User::query()->findOrFail((int) ($argv[5] ?? 0));
        app(ExamAllowanceService::class)->saveForTeacher($exam, $student, $teacher, 1, 0);
        $write(['event' => 'result', 'status' => 'allowance_saved']);
    } elseif ($operation === 'transfer-class') {
        DB::table('classes')->where('id', (int) ($argv[3] ?? 0))->update(['teacher_id' => (int) ($argv[4] ?? 0)]);
        $write(['event' => 'result', 'status' => 'ownership_transferred']);
    } elseif ($operation === 'unenroll') {
        DB::table('class_user')->where('class_id', (int) ($argv[3] ?? 0))->where('user_id', (int) ($argv[4] ?? 0))->delete();
        $write(['event' => 'result', 'status' => 'unenrolled']);
    } elseif ($operation === 'publish-report') {
        $class = SchoolClass::query()->findOrFail((int) ($argv[3] ?? 0));
        $user = User::query()->findOrFail((int) ($argv[4] ?? 0));
        $published = app(ReportArtifactPublisher::class)->publish($class, $user, $argv[5] ?? 'pdf');
        $write(['event' => 'result', 'status' => $published ? 'published' : 'unauthorized']);
    } elseif ($operation === 'claim-reports') {
        $write(['event' => 'result', 'claimed' => app(ReportScheduleService::class)->dispatchDue()]);
    } elseif ($operation === 'run-report') {
        app(ReportScheduleService::class)->execute((int) ($argv[3] ?? 0), isset($argv[4]) ? function () use ($argv): void {
            file_put_contents($argv[4], '1', FILE_APPEND | LOCK_EX);
            while (strlen((string) file_get_contents($argv[4])) < (int) $argv[5]) {
                usleep(20_000);
            }
        } : null);
        $write(['event' => 'result', 'status' => DB::table('report_runs')->where('id', (int) $argv[3])->value('status')]);
    } else {
        throw new InvalidArgumentException('Unknown concurrency worker operation.');
    }
} catch (ValidationException $exception) {
    $write(['event' => 'result', 'status' => 'validation_exception', 'errors' => $exception->errors()]);
} catch (HttpExceptionInterface $exception) {
    $write(['event' => 'result', 'status' => 'http_exception', 'code' => $exception->getStatusCode()]);
} catch (Throwable $exception) {
    $write(['event' => 'result', 'status' => 'error', 'message' => $exception->getMessage()]);
    exit(1);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect();
}
