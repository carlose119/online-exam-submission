<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['exam_id', 'student_id', 'additional_attempts', 'extra_time_minutes'])]
class ExamAllowance extends Model
{
    protected function casts(): array
    {
        return [
            'additional_attempts' => 'integer',
            'extra_time_minutes' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
