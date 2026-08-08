<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\AnswerOption;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnswerSelectionWriter
{
    /**
     * @param  array<int, mixed>  $selectedOptionIds
     */
    public function replace(StudentAttempt $attempt, Question $question, array $selectedOptionIds): void
    {
        $this->ensureQuestionBelongsToAttempt($attempt, $question);
        $this->validateSelection($question, $selectedOptionIds);

        DB::transaction(function () use ($attempt, $question, $selectedOptionIds): void {
            $this->ensureAttemptIsMutable($attempt, $question);
            $this->writeSelection($attempt, $question, $selectedOptionIds);
        });
    }

    /**
     * Apply one input change against the locked persisted selection.
     *
     * @return array<int, string>
     */
    public function updateOption(StudentAttempt $attempt, Question $question, int $optionId, bool $selected): array
    {
        $this->ensureQuestionBelongsToAttempt($attempt, $question);
        $this->validateSelection($question, [$optionId]);

        return DB::transaction(function () use ($attempt, $question, $optionId, $selected): array {
            $this->ensureAttemptIsMutable($attempt, $question);

            $selectedOptionIds = StudentAnswer::where('student_attempt_id', $attempt->id)
                ->where('question_id', $question->id)
                ->pluck('answer_option_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($question->type === QuestionType::Single) {
                $selectedOptionIds = $selected ? [$optionId] : [];
            } elseif ($selected) {
                $selectedOptionIds[] = $optionId;
                $selectedOptionIds = array_values(array_unique($selectedOptionIds));
            } else {
                $selectedOptionIds = array_values(array_diff($selectedOptionIds, [$optionId]));
            }

            $this->validateSelection($question, $selectedOptionIds);
            $this->writeSelection($attempt, $question, $selectedOptionIds);

            return array_map('strval', $selectedOptionIds);
        });
    }

    private function ensureQuestionBelongsToAttempt(StudentAttempt $attempt, Question $question): void
    {
        if ($question->exam_id !== $attempt->exam_id) {
            throw ValidationException::withMessages([
                'options' => 'The question does not belong to this exam attempt.',
            ]);
        }
    }

    private function ensureAttemptIsMutable(StudentAttempt $attempt, Question $question): void
    {
        $lockedAttempt = StudentAttempt::query()
            ->lockForUpdate()
            ->findOrFail($attempt->getKey());

        $this->ensureQuestionBelongsToAttempt($lockedAttempt, $question);

        if ($lockedAttempt->finished_at !== null) {
            throw ValidationException::withMessages([
                'options' => 'Answers cannot be changed after the exam attempt is finished.',
            ]);
        }
    }

    /** @param array<int, mixed> $selectedOptionIds */
    private function validateSelection(Question $question, array $selectedOptionIds): void
    {
        $optionRules = ['array'];
        if ($question->type === QuestionType::Single) {
            $optionRules[] = 'max:1';
        }

        Validator::make(
            ['options' => $selectedOptionIds],
            [
                'options' => $optionRules,
                'options.*' => [
                    'integer',
                    'distinct',
                    Rule::exists(AnswerOption::class, 'id')->where('question_id', $question->id),
                ],
            ],
            [
                'options.max' => 'A single-choice question accepts at most one option.',
                'options.*.distinct' => 'Each selected option must be distinct.',
                'options.*.exists' => 'Each selected option must belong to this question.',
            ],
        )->validate();
    }

    /** @param array<int, mixed> $selectedOptionIds */
    private function writeSelection(StudentAttempt $attempt, Question $question, array $selectedOptionIds): void
    {
        StudentAnswer::where('student_attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->delete();

        foreach ($selectedOptionIds as $optionId) {
            StudentAnswer::create([
                'student_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'answer_option_id' => (int) $optionId,
            ]);
        }
    }
}
