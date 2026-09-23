<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /** @return array<int, Carbon> */
    public static function occurrenceDates(Carbon $start, array $rule, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $frequency = $rule['frequency'] ?? 'weekly';
        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $days = $rule['days_of_week'] ?? null;
        $days = is_array($days) ? array_values(array_unique(array_filter(array_map(
            static fn ($day) => is_int($day) || is_string($day)
                ? filter_var($day, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]) : false,
            $days
        ), static fn ($day) => $day !== false))) : [];
        sort($days);

        if ($days && in_array($frequency, ['weekly', 'biweekly'], true)) {
            $first = $start->copy();
            while (! in_array($first->isoWeekday(), $days, true)) {
                $first->addDay();
            }

            $dates = [$first];
            $week = $first->copy()->startOfWeek(Carbon::MONDAY);
            $step = $interval * ($frequency === 'biweekly' ? 2 : 1);
            while (count($dates) < $count) {
                foreach ($days as $day) {
                    $date = $week->copy()->addDays($day - 1)->setTimeFrom($first);
                    if ($date->gt($first)) {
                        $dates[] = $date;
                        if (count($dates) === $count) {
                            break;
                        }
                    }
                }
                $week->addWeeks($step);
            }

            return $dates;
        }

        $dates = [$start->copy()];
        for ($i = 1; $i < $count; $i++) {
            $dates[] = match ($frequency) {
                'biweekly' => $start->copy()->addWeeks($interval * $i * 2),
                'monthly' => $start->copy()->addMonthsNoOverflow($interval * $i),
                default => $start->copy()->addWeeks($interval * $i),
            };
        }

        return $dates;
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

        $children = new Collection;

        foreach (array_slice(static::occurrenceDates($this->scheduled_at, $rule, $count), 1) as $childScheduledAt) {

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
