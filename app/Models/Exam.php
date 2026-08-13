<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['class_id', 'title', 'description', 'duration_minutes', 'max_score'])]
class Exam extends Model
{
    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'max_score' => 'integer',
        ];
    }

    /**
     * The class this exam belongs to.
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Questions in this exam, ordered by position.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('order')->orderBy('id');
    }

    /**
     * The attempts made by students for this exam.
     */
    public function studentAttempts(): HasMany
    {
        return $this->hasMany(StudentAttempt::class, 'exam_id');
    }

    /**
     * Student-specific attempt and time additions for this exam.
     */
    public function allowances(): HasMany
    {
        return $this->hasMany(ExamAllowance::class);
    }

    /**
     * Students enrolled in the class that owns this exam.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_user', 'class_id', 'user_id', 'class_id');
    }
}
