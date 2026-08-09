<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\StudentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ExamAttemptCreator
{
    public function __construct(private ExamAllowanceService $allowanceService) {}

    public function create(Exam $exam, int $studentId): StudentAttempt
    {
        return DB::transaction(function () use ($exam, $studentId): StudentAttempt {
            $student = User::query()->lockForUpdate()->findOrFail($studentId);
            $attempts = StudentAttempt::query()
                ->whereBelongsTo($student, 'student')
                ->whereBelongsTo($exam)
                ->get(['finished_at']);

            if ($attempts->contains(fn (StudentAttempt $attempt) => $attempt->finished_at === null)) {
                abort(403, 'You have already taken this exam.');
            }

            $limits = $this->allowanceService->limitsFor($exam, $student);
            $attemptNumber = $attempts->count() + 1;

            if ($attemptNumber > $limits->totalAttempts) {
                abort(403, 'You have already taken this exam.');
            }

            return StudentAttempt::query()->create([
                'student_id' => $student->id,
                'exam_id' => $exam->id,
                'attempt_number' => $attemptNumber,
                'allowed_duration_minutes' => $limits->durationMinutes,
                'started_at' => now(),
            ]);
        });
    }
}
