<?php

namespace App\Filament\Resources\ClassReportResource\Pages;

use App\Filament\Resources\ClassReportResource;
use App\Jobs\GenerateClassReportExcel;
use App\Jobs\GenerateClassReportPdf;
use App\Models\SchoolClass;
use App\Services\ClassReportService;
use App\Services\ReportAccess;
use App\Services\ReportFormatService;
use App\Values\ReportFilters;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ClassReport extends Page
{
    protected static string $resource = ClassReportResource::class;

    protected string $view = 'filament.resources.class-report-resource.pages.class-report';

    public SchoolClass $record;

    public array $reportData = [];

    public array $filters = ReportFilters::EMPTY;

    public function mount(SchoolClass $record): void
    {
        app(ReportAccess::class)->authorize(auth()->user(), $record);

        $this->record = $record;
        $this->reportData = app(ClassReportService::class)->generate($record);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $totalAttempts = $this->reportData['overall_stats']['total_attempts'] ?? 0;
        $isSync = $totalAttempts < (int) config('reports.sync_threshold', 100);

        return [
            Action::make('filters')
                ->icon('heroicon-o-funnel')
                ->fillForm(fn (): array => $this->filters)
                ->schema([
                    Select::make('exam_ids')->label('Exams')->multiple()
                        ->options(fn (): array => $this->filterOptions('exams', 'title', 'id')),
                    Select::make('student_ids')->label('Students')->multiple()->searchable()
                        ->options(fn (): array => $this->filterOptions('students', 'name', 'users.id')),
                    Select::make('statuses')->label('Attempt status')->multiple()->options([
                        'in_progress' => 'In progress', 'passed' => 'Passed', 'failed' => 'Failed',
                    ]),
                    DateTimePicker::make('started_from')->label('Started from'),
                    DateTimePicker::make('started_until')->label('Started until')->afterOrEqual('started_from'),
                ])
                ->action(function (array $data): void {
                    app(ReportAccess::class)->authorize(auth()->user(), $this->record->refresh());
                    $this->filters = ReportFilters::fromTrustedForm($data, $this->record)->toArray();
                    $this->reportData = app(ClassReportService::class)->generate($this->record, $this->filters);
                }),
            Action::make('clearFilters')
                ->label('Clear filters')
                ->color('gray')
                ->visible(fn (): bool => $this->filters !== ReportFilters::EMPTY)
                ->action(function (): void {
                    app(ReportAccess::class)->authorize(auth()->user(), $this->record->refresh());
                    $this->filters = ReportFilters::trustedEmpty();
                    $this->reportData = app(ClassReportService::class)->generate($this->record);
                }),
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () use ($isSync): mixed {
                    app(ReportAccess::class)->authorize(auth()->user(), $this->record->refresh());

                    if ($isSync) {
                        $data = app(ClassReportService::class)->generate($this->record, $this->filters);
                        $filename = app(ReportFormatService::class)->toPdf($data, $this->record);

                        return redirect()->route('reports.download', ['filename' => $filename]);
                    }

                    GenerateClassReportPdf::dispatch($this->record->id, Auth::id(), $this->filters);

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
                    app(ReportAccess::class)->authorize(auth()->user(), $this->record->refresh());

                    if ($isSync) {
                        $data = app(ClassReportService::class)->generate($this->record, $this->filters);
                        $filename = app(ReportFormatService::class)->toExcel($data, $this->record);

                        return redirect()->route('reports.download', ['filename' => $filename]);
                    }

                    GenerateClassReportExcel::dispatch($this->record->id, Auth::id(), $this->filters);

                    Notification::make()
                        ->title('Excel Report Queued')
                        ->success()
                        ->body("The Excel report for \"{$this->record->title}\" has been queued. You will be notified when it is ready.")
                        ->send();

                    return null;
                }),
        ];
    }

    private function filterOptions(string $relation, string $label, string $key): array
    {
        app(ReportAccess::class)->authorize(auth()->user(), $this->record->refresh());

        return $this->record->{$relation}()->orderBy($label)->pluck($label, $key)->all();
    }
}
