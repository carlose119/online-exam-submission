<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['exam_id', 'text', 'type', 'points', 'order'])]
class Question extends Model
{
    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
        ];
    }

    /**
     * The exam this question belongs to.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * Answer options for this question, ordered by id for stable display.
     */
    public function options(): HasMany
    {
        return $this->hasMany(AnswerOption::class)->orderBy('id');
    }
}
