<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\StudentAttempt;

class ExamAccessGuard
{
    public function ensureSubscribed(Exam $exam, int $studentId): void
    {
        if (! $exam->classroom->students()->where('users.id', $studentId)->exists()) {
            abort(403, 'You are not subscribed to this class.');
        }
    }

    public function ensureCanTake(StudentAttempt $attempt, int $studentId): void
    {
        if ($attempt->student_id !== $studentId) {
            abort(403);
        }

        if ($attempt->finished_at === null) {
            $this->ensureSubscribed($attempt->exam, $studentId);
        }
    }
}
