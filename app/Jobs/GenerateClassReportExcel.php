<?php

namespace App\Jobs;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ClassReportService;
use App\Services\ReportFormatService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateClassReportExcel implements ShouldQueue
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
    public function handle(ClassReportService $service, ReportFormatService $formatter): void
    {
        $class = SchoolClass::find($this->classId);

        if (! $class) {
            return;
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $data = $service->generate($class);
        $filename = $formatter->toExcel($data, $class);

        Notification::make()
            ->title('Excel Report Ready')
            ->body("The Excel report for \"{$class->title}\" has been generated.")
            ->success()
            ->actions([
                Action::make('download')
                    ->label('Download Excel')
                    ->url(route('reports.download', ['filename' => $filename])),
            ])
            ->sendToDatabase($user);
    }
}
