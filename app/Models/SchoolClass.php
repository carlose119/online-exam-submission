<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'syllabus', 'teacher_id', 'invitation_code'])]
class SchoolClass extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'classes';

    /**
     * The teacher who owns this class.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Study materials attached to this class.
     */
    public function studyMaterials(): HasMany
    {
        return $this->hasMany(StudyMaterial::class, 'class_id');
    }

    /**
     * Exams attached to this class.
     */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'class_id');
    }

    /**
     * Students subscribed to this class.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_user', 'class_id', 'user_id')->withTimestamps();
    }
}
