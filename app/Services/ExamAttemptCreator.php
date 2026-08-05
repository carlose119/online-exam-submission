<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\StudentAttempt;

class ExamAttemptCreator
{
    public function create(Exam $exam, int $studentId): StudentAttempt
    {
        $attempt = StudentAttempt::query()->createOrFirst(
            ['student_id' => $studentId, 'exam_id' => $exam->id],
            ['started_at' => now()],
        );

        if (! $attempt->wasRecentlyCreated) {
            abort(403, 'You have already taken this exam.');
        }

        return $attempt;
    }
}
