<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditExam extends EditRecord
{
    protected static string $resource = ExamResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview as student')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalContent(function (): HtmlString {
                    $exam = $this->getRecord()->load('questions.options');

                    $data = [
                        'title' => $exam->title,
                        'description' => $exam->description,
                        'duration_minutes' => $exam->duration_minutes,
                        'max_score' => $exam->max_score,
                        'questions' => $exam->questions->map(fn ($q) => [
                            'text' => $q->text,
                            'type' => $q->type->value,
                            'points' => $q->points,
                            'options' => $q->options->map(fn ($o) => [
                                'text' => $o->text,
                                'is_correct' => $o->is_correct,
                            ])->toArray(),
                        ])->toArray(),
                    ];

                    return new HtmlString(
                        '<pre style="max-height: 400px; overflow-y: auto; background: #1e1e2e; color: #cdd6f4; padding: 1rem; border-radius: 0.5rem; font-size: 0.875rem;">'
                        . e(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        . '</pre>'
                    );
                }),
        ];
    }
}
