<?php

namespace App\Models;

use App\Enums\StudyMaterialType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['class_id', 'title', 'type', 'file_path_or_url', 'extra_metadata'])]
#[Hidden([])]
class StudyMaterial extends Model
{
    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'extra_metadata' => 'array',
            'type' => StudyMaterialType::class,
        ];
    }

    /**
     * The class this material belongs to.
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
