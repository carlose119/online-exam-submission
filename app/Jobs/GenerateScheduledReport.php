<?php

namespace App\Jobs;

use App\Services\ReportScheduleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateScheduledReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $runId) {}

    public function handle(ReportScheduleService $schedules): void
    {
        $schedules->execute($this->runId);
    }
}
