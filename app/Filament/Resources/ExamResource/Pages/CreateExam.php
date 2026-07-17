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
        // Validate at least one correct option per question
        foreach ($data['questions'] ?? [] as $qIndex => $question) {
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
        foreach ($data['questions'] ?? [] as $question) {
            $totalPoints += (int) ($question['points'] ?? 0);
        }

        // Default max_score to sum of question points if not explicitly overridden
        $explicitMaxScore = $data['max_score'] ?? 100;
        if ($totalPoints > 0 && $explicitMaxScore == 100) {
            $data['max_score'] = $totalPoints;
        }

        // Set order on each question from its Repeater position (0-indexed)
        foreach ($data['questions'] ?? [] as $index => &$question) {
            $question['order'] = $index;
        }

        return $data;
    }
}
