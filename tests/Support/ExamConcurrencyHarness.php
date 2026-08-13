<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

final class ExamConcurrencyHarness
{
    public static function start(string $operation, int ...$ids): array
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

    public static function message(array &$worker, float $timeout = 10): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            $contents = @file_get_contents($worker['ipc_path']);
            if ($contents !== false) {
                $worker['buffer'] .= substr($contents, $worker['ipc_offset']);
                $worker['ipc_offset'] = strlen($contents);
                if (($newline = strpos($worker['buffer'], "\n")) !== false) {
                    $line = substr($worker['buffer'], 0, $newline);
                    $worker['buffer'] = substr($worker['buffer'], $newline + 1);

                    return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            }
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                throw new RuntimeException('Worker exited before sending a complete message: '.self::diagnostics($worker, $status));
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Worker message deadline exceeded: '.self::diagnostics($worker, $status));
    }

    public static function observeLockWait(array &$worker, int $connectionId): array
    {
        $config = config('database.connections.mysql');
        $monitor = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $deadline = microtime(true) + 10;
        do {
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                throw new RuntimeException("Worker connection {$connectionId} exited before waiting: ".self::diagnostics($worker, $status));
            }
            $transaction = $monitor->query(
                "SELECT trx_id, trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = {$connectionId}",
            )->fetch(PDO::FETCH_ASSOC);
            if (($transaction['trx_state'] ?? null) === 'LOCK WAIT') {
                $wait = $monitor->query(
                    "SELECT requesting_trx_id, blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS WHERE requesting_trx_id = {$transaction['trx_id']}",
                )->fetch(PDO::FETCH_ASSOC);
                if ($wait !== false) {
                    return $wait + ['waiting_connection_id' => $connectionId];
                }
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("MariaDB lock-wait evidence missing for connection {$connectionId}: ".self::diagnostics($worker, $status));
    }

    public static function stop(?array &$worker): void
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

    private static function diagnostics(array $worker, array $status): string
    {
        $diagnostics = ['exit_code' => $status['exitcode'], 'ipc' => trim((string) @file_get_contents($worker['ipc_path']))];
        if (! $status['running']) {
            $diagnostics['stdout'] = trim((string) stream_get_contents($worker['pipes'][1]));
            $diagnostics['stderr'] = trim((string) stream_get_contents($worker['pipes'][2]));
        }

        return json_encode($diagnostics, JSON_THROW_ON_ERROR);
    }
}
