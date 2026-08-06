<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['class_id', 'title', 'scheduled_at', 'duration_minutes', 'meeting_url', 'agenda', 'recurrence_rule', 'parent_id'])]
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

    // -----------------------------------------------------------------------
    // Recurrence relations & methods
    // -----------------------------------------------------------------------

    /**
     * The parent meeting (if this is a recurring instance).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'parent_id');
    }

    /**
     * Child instances of this recurring parent, ordered by scheduled time.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Meeting::class, 'parent_id')->orderBy('scheduled_at', 'asc');
    }

    /**
     * Whether this meeting defines a recurrence rule (i.e. is a parent).
     */
    public function isRecurring(): bool
    {
        return $this->recurrence_rule !== null;
    }

    /**
     * Decode the recurrence rule JSON into an array.
     */
    public function recurrenceRule(): ?array
    {
        return $this->recurrence_rule
            ? json_decode($this->recurrence_rule, true)
            : null;
    }

    /**
     * Encode a recurrence rule array into the raw JSON column.
     */
    public function setRecurrenceRule(?array $value): void
    {
        if (is_null($value)) {
            $this->attributes['recurrence_rule'] = null;
        } else {
            $this->attributes['recurrence_rule'] = json_encode($value);
        }
    }

    /**
     * Eagerly materialize N-1 child meeting instances from this parent's
     * recurrence rule. Returns the collection of created children.
     */
    public function generateInstances(int $count): Collection
    {
        $rule = $this->recurrenceRule();

        if ($rule === null || $count <= 1) {
            return new Collection;
        }

        $frequency = $rule['frequency'] ?? 'weekly';
        $interval = (int) ($rule['interval'] ?? 1);
        $childCount = $count - 1;
        $children = new Collection;

        for ($i = 1; $i <= $childCount; $i++) {
            $childScheduledAt = match ($frequency) {
                'biweekly' => $this->scheduled_at->copy()->addWeeks($interval * $i * 2),
                'monthly' => $this->scheduled_at->copy()->addMonthsNoOverflow($interval * $i),
                default => $this->scheduled_at->copy()->addWeeks($interval * $i), // weekly
            };

            $children->push(static::create([
                'class_id' => $this->class_id,
                'title' => $this->title,
                'scheduled_at' => $childScheduledAt,
                'duration_minutes' => $this->duration_minutes ?? 60,
                'meeting_url' => $this->meeting_url,
                'agenda' => $this->agenda,
                'recurrence_rule' => null,
                'parent_id' => $this->id,
            ]));
        }

        return $children;
    }
}
