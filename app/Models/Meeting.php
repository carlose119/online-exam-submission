<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['class_id', 'title', 'scheduled_at', 'duration_minutes', 'meeting_url', 'agenda'])]
class Meeting extends Model
{
    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * The classroom this meeting belongs to.
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Scope: meetings scheduled in the future (now or later).
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('scheduled_at', '>=', now());
    }

    /**
     * Scope: meetings whose scheduled time has already passed.
     */
    public function scopePast(Builder $query): void
    {
        $query->where('scheduled_at', '<', now());
    }

    /**
     * Scope: meetings within the ±15 min live window AND with a meeting URL set.
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotNull('meeting_url')
            ->where('scheduled_at', '<=', now()->addMinutes(15))
            ->where('scheduled_at', '>=', now()->subMinutes(15));
    }

    /**
     * Whether this meeting instance is currently "live" (within the ±15 min window
     * AND has a meeting URL set).
     */
    public function isLive(): bool
    {
        return $this->meeting_url !== null
            && $this->scheduled_at->gte(now()->subMinutes(15))
            && $this->scheduled_at->lte(now()->addMinutes(15));
    }

    /**
     * Whether this meeting's scheduled time is in the past.
     */
    public function isPast(): bool
    {
        return $this->scheduled_at->lt(now());
    }
}
