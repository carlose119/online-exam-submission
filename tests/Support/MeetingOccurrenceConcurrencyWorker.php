<?php

use App\Models\Meeting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ipcPath = $argv[1] ?? throw new InvalidArgumentException('Worker IPC path is required.');
$write = static function (array $message) use ($ipcPath): void {
    file_put_contents($ipcPath, json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
};

try {
    if (DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== 'online_exam_submission_concurrency') {
        throw new RuntimeException('Refusing to run against a non-disposable database.');
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    $write(['event' => 'ready', 'connection_id' => (int) DB::scalar('SELECT CONNECTION_ID()')]);
    $deleted = Meeting::query()->findOrFail((int) ($argv[2] ?? 0))->deleteOccurrence();
    $write(['event' => 'result', 'status' => $deleted ? 'deleted' : 'not_deleted']);
} catch (Throwable $exception) {
    $write(['event' => 'result', 'status' => 'error', 'message' => $exception->getMessage()]);
    exit(1);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect();
}
