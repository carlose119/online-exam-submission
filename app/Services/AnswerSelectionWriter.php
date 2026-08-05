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
        if ($question->exam_id !== $attempt->exam_id) {
            throw ValidationException::withMessages([
                'options' => 'The question does not belong to this exam attempt.',
            ]);
        }

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

        DB::transaction(function () use ($attempt, $question, $selectedOptionIds): void {
            $lockedAttempt = StudentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->getKey());

            if ($question->exam_id !== $lockedAttempt->exam_id) {
                throw ValidationException::withMessages([
                    'options' => 'The question does not belong to this exam attempt.',
                ]);
            }

            if ($lockedAttempt->finished_at !== null) {
                throw ValidationException::withMessages([
                    'options' => 'Answers cannot be changed after the exam attempt is finished.',
                ]);
            }

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
        });
    }
}
