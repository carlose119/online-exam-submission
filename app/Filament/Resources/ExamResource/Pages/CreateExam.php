<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateExam extends CreateRecord
{
    protected static string $resource = ExamResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // max_score defaulting and correct-option validation need access to the
        // relationship Repeater data. Relationship state is NOT in $data when
        // ->relationship() is used on Repeaters (Filament strips it before
        // calling this hook). Access it via $this->data instead.
        return $data;
    }

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

        // Compute total points from all questions
        $totalPoints = 0;
        foreach ($questions as $question) {
            $totalPoints += (int) ($question['points'] ?? 0);
        }

        // Default max_score to sum of question points if not explicitly overridden.
        // Modify $this->data BEFORE $this->form->getState() captures it in create().
        $explicitMaxScore = $this->data['max_score'] ?? 100;
        if ($totalPoints > 0 && $explicitMaxScore == 100) {
            $this->data['max_score'] = $totalPoints;
        }
    }
}
