<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReportAccess
{
    public function scope(Builder $query, ?User $user): Builder
    {
        if ($user?->role === 'ADMIN') {
            return $query;
        }

        if ($user?->role === 'TEACHER') {
            return $query->where('teacher_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function authorize(?User $user, SchoolClass $class): void
    {
        abort_unless($this->allows($user, $class), 403);
    }

    public function allows(?User $user, SchoolClass $class): bool
    {
        return $user?->role === 'ADMIN'
            || ($user?->role === 'TEACHER' && (int) $class->teacher_id === $user->id);
    }
}
