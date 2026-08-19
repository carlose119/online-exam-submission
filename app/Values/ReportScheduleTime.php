<?php

namespace App\Values;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class ReportScheduleTime
{
    /** Nonexistent DST wall times are skipped; ambiguous times use the earliest UTC instant. */
    public function next(string $recurrence, mixed $weekday, string $time, string $timezone, ?CarbonImmutable $after = null): CarbonImmutable
    {
        if (! in_array($recurrence, ['daily', 'weekly'], true)) {
            $this->invalid('recurrence');
        }
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $this->invalid('local_time');
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->invalid('timezone');
        }
        if (($recurrence === 'weekly' && (! is_int($weekday) || $weekday < 1 || $weekday > 7))
            || ($recurrence === 'daily' && $weekday !== null)) {
            $this->invalid('weekday');
        }

        $after ??= CarbonImmutable::now('UTC');
        $date = $after->setTimezone($timezone)->startOfDay();
        if ($recurrence === 'weekly') {
            $date = $date->addDays(($weekday - $date->dayOfWeekIso + 7) % 7);
        }

        for ($attempt = 0; $attempt < 370; $attempt++) {
            $candidate = $this->resolve($date->format('Y-m-d').' '.$time, $timezone);
            if ($candidate?->greaterThan($after)) {
                return $candidate;
            }
            $date = $recurrence === 'daily' ? $date->addDay() : $date->addWeek();
        }

        throw new \RuntimeException('Unable to calculate the next report occurrence.');
    }

    private function resolve(string $wall, string $timezone): ?CarbonImmutable
    {
        $naive = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, 'UTC');
        $zone = new DateTimeZone($timezone);
        $candidates = [];
        foreach ($zone->getTransitions($naive->timestamp - 172800, $naive->timestamp + 172800) as $transition) {
            $candidate = CarbonImmutable::createFromTimestampUTC($naive->timestamp - $transition['offset']);
            if ($candidate->setTimezone($timezone)->format('Y-m-d H:i') === $wall) {
                $candidates[$candidate->timestamp] = $candidate;
            }
        }
        ksort($candidates);

        return $candidates ? reset($candidates) : null;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => "The {$field} value is invalid."]);
    }
}
