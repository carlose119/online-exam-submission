<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ClassUser extends Pivot
{
    /**
     * The table associated with the pivot model.
     */
    protected $table = 'class_user';
}
