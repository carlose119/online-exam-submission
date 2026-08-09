<?php

namespace App\Domain\Exams;

use App\Models\Exam;
use App\Models\ExamAllowance;

final class EffectiveExamLimitResolver
{
    public function resolve(Exam $exam, ?ExamAllowance $allowance = null): EffectiveExamLimits
    {
        return new EffectiveExamLimits(
            totalAttempts: 1 + ($allowance?->additional_attempts ?? 0),
            durationMinutes: $exam->duration_minutes + ($allowance?->extra_time_minutes ?? 0),
        );
    }
}
