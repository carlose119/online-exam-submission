<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['student_id', 'exam_id', 'attempt_number', 'allowed_duration_minutes', 'score_obtained', 'started_at', 'finished_at'])]
class StudentAttempt extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'student_attempts';

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'allowed_duration_minutes' => 'integer',
            'score_obtained' => 'decimal:2',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The student that owns this attempt.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The exam this attempt is for.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function deadline(): CarbonInterface
    {
        $durationMinutes = $this->allowed_duration_minutes ?? $this->exam->duration_minutes;

        return $this->started_at->copy()->addMinutes($durationMinutes);
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return ($at ?? now())->greaterThan($this->deadline());
    }

    /**
     * The answers recorded for this attempt.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class);
    }
}
