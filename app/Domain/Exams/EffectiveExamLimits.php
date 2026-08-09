<?php

namespace App\Domain\Exams;

final readonly class EffectiveExamLimits
{
    public function __construct(
        public int $totalAttempts,
        public int $durationMinutes,
    ) {}
}
