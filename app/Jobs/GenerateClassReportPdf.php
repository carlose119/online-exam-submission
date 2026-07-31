<?php

namespace App\Jobs;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ClassReportService;
use App\Services\ReportFormatService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateClassReportPdf implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  int  $classId
     * @param  int  $userId
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
        $filename = $formatter->toPdf($data, $class);

        Notification::make()
            ->title('PDF Report Ready')
            ->body("The PDF report for \"{$class->title}\" has been generated.")
            ->success()
            ->actions([
                Action::make('download')
                    ->label('Download PDF')
                    ->url(route('reports.download', ['filename' => $filename])),
            ])
            ->sendToDatabase($user);
    }
}
