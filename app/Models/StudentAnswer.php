<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_attempt_id', 'question_id', 'answer_option_id'])]
class StudentAnswer extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'student_answers';

    /**
     * The attempt this answer belongs to.
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(StudentAttempt::class, 'student_attempt_id');
    }

    /**
     * The question this answer is for.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * The selected answer option.
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(AnswerOption::class, 'answer_option_id');
    }
}
