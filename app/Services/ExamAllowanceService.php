<?php

namespace App\Services;

use App\Domain\Exams\EffectiveExamLimitResolver;
use App\Domain\Exams\EffectiveExamLimits;
use App\Models\Exam;
use App\Models\ExamAllowance;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExamAllowanceService
{
    public const MAX_ADDITIONAL_ATTEMPTS = 100;

    public const MAX_EXTRA_TIME_MINUTES = 1440;

    public function __construct(private EffectiveExamLimitResolver $limitResolver) {}

    public function limitsFor(Exam $exam, User $student): EffectiveExamLimits
    {
        $allowance = $exam->relationLoaded('allowances')
            ? $exam->allowances->firstWhere('student_id', $student->id)
            : ExamAllowance::query()
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
        return $this->persist($exam, $student, $additionalAttempts, $extraTimeMinutes);
    }

    public function saveForTeacher(
        Exam $exam,
        User $student,
        User $teacher,
        int $additionalAttempts,
        int $extraTimeMinutes,
    ): ExamAllowance {
        return $this->persist($exam, $student, $additionalAttempts, $extraTimeMinutes, $teacher);
    }

    private function persist(
        Exam $exam,
        User $student,
        int $additionalAttempts,
        int $extraTimeMinutes,
        ?User $teacher = null,
    ): ExamAllowance {
        if ($additionalAttempts < 0 || $additionalAttempts > self::MAX_ADDITIONAL_ATTEMPTS) {
            throw ValidationException::withMessages([
                'additional_attempts' => 'Additional attempts must be between 0 and '.self::MAX_ADDITIONAL_ATTEMPTS.'.',
            ]);
        }
        if ($extraTimeMinutes < 0 || $extraTimeMinutes > self::MAX_EXTRA_TIME_MINUTES) {
            throw ValidationException::withMessages([
                'extra_time_minutes' => 'Additional time must be between 0 and '.self::MAX_EXTRA_TIME_MINUTES.' minutes.',
            ]);
        }

        return DB::transaction(function () use ($exam, $student, $teacher, $additionalAttempts, $extraTimeMinutes): ExamAllowance {
            $student = User::query()->lockForUpdate()->findOrFail($student->id);
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->id);
            $classroom = SchoolClass::query()->lockForUpdate()->findOrFail($exam->class_id);

            if ($teacher !== null && $classroom->teacher_id !== $teacher->id) {
                abort(403);
            }

            $enrollment = DB::table('class_user')
                ->where('class_id', $classroom->id)
                ->where('user_id', $student->id)
                ->lockForUpdate()
                ->first(['id']);
            if ($enrollment === null) {
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
