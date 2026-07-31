<?php

namespace App\Filament\Resources\ClassReportResource\Pages;

use App\Filament\Resources\ClassReportResource;
use App\Jobs\GenerateClassReportExcel;
use App\Jobs\GenerateClassReportPdf;
use App\Models\SchoolClass;
use App\Services\ClassReportService;
use App\Services\ReportFormatService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ClassReport extends Page
{
    protected static string $resource = ClassReportResource::class;

    protected string $view = 'filament.resources.class-report-resource.pages.class-report';

    public SchoolClass $record;

    public array $reportData = [];

    public function mount(SchoolClass $record): void
    {
        $this->record = $record;
        $this->reportData = app(ClassReportService::class)->generate($record);
    }

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        $totalAttempts = $this->reportData['overall_stats']['total_attempts'] ?? 0;
        $isSync = $totalAttempts < (int) config('reports.sync_threshold', 100);

        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () use ($isSync): mixed {
                    if ($isSync) {
                        $data = app(ClassReportService::class)->generate($this->record);
                        $filename = app(ReportFormatService::class)->toPdf($data, $this->record);

                        return redirect()->route('reports.download', ['filename' => $filename]);
                    }

                    GenerateClassReportPdf::dispatch($this->record->id, Auth::id());

                    Notification::make()
                        ->title('PDF Report Queued')
                        ->success()
                        ->body("The PDF report for \"{$this->record->title}\" has been queued. You will be notified when it is ready.")
                        ->send();

                    return null;
                }),

            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->action(function () use ($isSync): mixed {
                    if ($isSync) {
                        $data = app(ClassReportService::class)->generate($this->record);
                        $filename = app(ReportFormatService::class)->toExcel($data, $this->record);

                        return redirect()->route('reports.download', ['filename' => $filename]);
                    }

                    GenerateClassReportExcel::dispatch($this->record->id, Auth::id());

                    Notification::make()
                        ->title('Excel Report Queued')
                        ->success()
                        ->body("The Excel report for \"{$this->record->title}\" has been queued. You will be notified when it is ready.")
                        ->send();

                    return null;
                }),
        ];
    }
}
