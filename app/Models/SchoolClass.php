<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * Students subscribed to this class.
     *
     * The class_user pivot table is deferred — this relationship is
     * declared but NOT wired to a real pivot table in this slice.
     * Uncomment the return when the class_user migration is created.
     */
    // public function students(): BelongsToMany
    // {
    //     return $this->belongsToMany(User::class, 'class_user');
    // }
}
