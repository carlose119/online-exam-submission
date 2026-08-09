<?php

namespace App\Services;

use App\Domain\Exams\EffectiveExamLimitResolver;
use App\Domain\Exams\EffectiveExamLimits;
use App\Models\Exam;
use App\Models\ExamAllowance;
use App\Models\User;
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

        if (! $exam->classroom()->whereHas('students', fn ($query) => $query->whereKey($student->id))->exists()) {
            throw ValidationException::withMessages([
                'student_id' => 'The student must be enrolled in the exam class.',
            ]);
        }

        return ExamAllowance::query()->updateOrCreate(
            ['exam_id' => $exam->id, 'student_id' => $student->id],
            ['additional_attempts' => $additionalAttempts, 'extra_time_minutes' => $extraTimeMinutes],
        );
    }
}
