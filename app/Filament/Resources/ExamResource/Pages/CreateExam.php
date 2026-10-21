<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateExam extends CreateRecord
{
    protected static string $resource = ExamResource::class;

    protected function beforeValidate(): void
    {
        $questions = $this->data['questions'] ?? [];

        // Validate at least one correct option per question
        foreach ($questions as $qIndex => $question) {
            $hasCorrect = false;
            foreach ($question['options'] ?? [] as $option) {
                if (! empty($option['is_correct'])) {
                    $hasCorrect = true;
                    break;
                }
            }
            if (! $hasCorrect) {
                throw ValidationException::withMessages([
                    "questions.{$qIndex}.options" => 'Each question must have at least one correct option.',
                ]);
            }
        }
    }
}
