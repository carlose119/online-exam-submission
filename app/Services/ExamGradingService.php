<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\StudentAttempt;

class ExamGradingService
{
    /**
     * Grade a student attempt using the strict multiple-choice rules.
     *
     * SINGLE questions: full points if exactly one answer row exists AND
     * the selected option is correct. Otherwise 0.
     *
     * MULTIPLE questions: full points only when ALL correct options are
     * selected AND NO incorrect option is selected. Otherwise 0.
     *
     * The method is idempotent: if finished_at is already set, it returns
     * the existing score_obtained without recomputing.
     *
     * @return float The total score (sum of per-question points).
     */
    public function gradeAttempt(StudentAttempt $attempt): float
    {
        // Idempotency: if already graded, return existing score.
        if ($attempt->finished_at !== null) {
            return (float) $attempt->score_obtained;
        }

        $totalScore = 0.0;
        $exam = $attempt->exam()->firstOrFail();
        $questions = $exam->questions()->orderBy('order')->get();

        foreach ($questions as $question) {
            $studentAnswers = $attempt->answers()
                ->where('question_id', $question->id)
                ->get();

            if ($question->type === QuestionType::Single) {
                $totalScore += $this->gradeSingle($question, $studentAnswers);
            } elseif ($question->type === QuestionType::Multiple) {
                $totalScore += $this->gradeMultiple($question, $studentAnswers);
            }
        }

        $attempt->update([
            'score_obtained' => $totalScore,
            'finished_at' => now(),
        ]);

        return $totalScore;
    }

    /**
     * Grade a SINGLE question.
     *
     * Awards full points when exactly one answer exists AND the selected
     * option is marked correct. All other cases award 0.
     */
    private function gradeSingle($question, $studentAnswers): float
    {
        // Must have exactly one answer
        if ($studentAnswers->count() !== 1) {
            return 0.0;
        }

        $answer = $studentAnswers->first();
        $option = $answer->option;

        // The selected option must be the correct one
        if ($option && $option->question_id === $question->id && $option->is_correct === true) {
            return (float) ($question->points ?? 0);
        }

        return 0.0;
    }

    /**
     * Grade a MULTIPLE question using strict rules.
     *
     * Awards full points only when the student selected EVERY correct option
     * AND selected NO incorrect option. Any deviation awards 0.
     */
    private function gradeMultiple($question, $studentAnswers): float
    {
        $correctOptionIds = $question->options()
            ->where('is_correct', true)
            ->pluck('id');

        $selectedOptionIds = $studentAnswers->pluck('answer_option_id');

        // If student selected nothing and there are correct options → 0
        if ($selectedOptionIds->isEmpty()) {
            return 0.0;
        }

        // If there are no correct options (edge case, should not happen), award 0
        if ($correctOptionIds->isEmpty()) {
            return 0.0;
        }

        // Strict check: all correct options must be selected AND no incorrect selected
        $allCorrectSelected = $correctOptionIds->diff($selectedOptionIds)->isEmpty();
        $noIncorrectSelected = $selectedOptionIds->diff($correctOptionIds)->isEmpty();

        if ($allCorrectSelected && $noIncorrectSelected) {
            return (float) ($question->points ?? 0);
        }

        return 0.0;
    }
}
