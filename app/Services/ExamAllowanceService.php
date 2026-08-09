<?php

namespace App\Services;

use App\Domain\Exams\EffectiveExamLimitResolver;
use App\Domain\Exams\EffectiveExamLimits;
use App\Models\Exam;
use App\Models\ExamAllowance;
use App\Models\StudentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExamAllowanceService
{
    public function __construct(private EffectiveExamLimitResolver $limitResolver) {}

    public function limitsFor(Exam $exam, User $student): EffectiveExamLimits
    {
        $allowance = ExamAllowance::query()
            ->whereBelongsTo($exam)
            ->whereBelongsTo($student, 'student')
            ->first();

        return $this->limitResolver->resolve($exam, $allowance);
    }

    public function save(
        Exam $exam,
        User $student,
        int $additionalAttempts,
        int $extraTimeMinutes,
    ): ExamAllowance {
        if ($additionalAttempts < 0 || $extraTimeMinutes < 0) {
            throw ValidationException::withMessages([
                'allowance' => 'Exam allowance values must be non-negative.',
            ]);
        }

        return DB::transaction(function () use ($exam, $student, $additionalAttempts, $extraTimeMinutes): ExamAllowance {
            $student = User::query()->lockForUpdate()->findOrFail($student->id);

            if (! $exam->classroom()->whereHas('students', fn ($query) => $query->whereKey($student->id))->exists()) {
                throw ValidationException::withMessages([
                    'student_id' => 'The student must be enrolled in the exam class.',
                ]);
            }

            $consumedAdditionalAttempts = max(0, StudentAttempt::query()
                ->whereBelongsTo($student, 'student')
                ->whereBelongsTo($exam)
                ->count() - 1);

            if ($additionalAttempts < $consumedAdditionalAttempts) {
                throw ValidationException::withMessages([
                    'additional_attempts' => 'Additional attempts cannot be lower than the number already consumed.',
                ]);
            }

            return ExamAllowance::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'student_id' => $student->id],
                ['additional_attempts' => $additionalAttempts, 'extra_time_minutes' => $extraTimeMinutes],
            );
        });
    }
}
