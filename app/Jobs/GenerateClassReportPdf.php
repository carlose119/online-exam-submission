<?php

namespace App\Jobs;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ReportArtifactPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateClassReportPdf implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $classId,
        private readonly int $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ReportArtifactPublisher $publisher): void
    {
        $class = SchoolClass::find($this->classId);

        if (! $class) {
            return;
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $publisher->publish($class, $user, 'pdf');
    }
}
