<?php

namespace App\Values;

use App\Models\SchoolClass;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class ReportFilters
{
    public const VERSION = 1;

    public const EMPTY = ['version' => 1, 'exam_ids' => [], 'student_ids' => [], 'statuses' => [], 'started_from' => null, 'started_until' => null];

    private const STATUSES = ['in_progress', 'passed', 'failed'];

    public function __construct(
        public array $examIds = [],
        public array $studentIds = [],
        public array $statuses = [],
        public ?string $startedFrom = null,
        public ?string $startedUntil = null,
    ) {}

    public static function trustedEmpty(): array
    {
        return self::EMPTY;
    }

    public static function fromTrustedForm(array $input, SchoolClass $class): self
    {
        foreach (['exam_ids', 'student_ids'] as $key) {
            if (! is_array($input[$key] ?? [])) {
                throw ValidationException::withMessages([$key => 'The selected values are invalid.']);
            }
            $input[$key] = array_map(function ($value) use ($key): int {
                if (is_int($value)) {
                    return $value;
                }
                if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d*)$/', $value)
                    || filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw ValidationException::withMessages([$key => 'The selected values are invalid.']);
                }

                return (int) $value;
            }, $input[$key] ?? []);
        }
        foreach (['started_from', 'started_until'] as $key) {
            if (! empty($input[$key])) {
                $input[$key] = CarbonImmutable::parse($input[$key], config('app.timezone'))->toISOString();
            }
        }

        return self::from([...$input, 'version' => self::VERSION], $class);
    }

    public static function from(array $input, SchoolClass $class): self
    {
        if (($input['version'] ?? null) !== self::VERSION) {
            throw ValidationException::withMessages(['filters' => 'The report filter version is invalid.']);
        }

        $examIds = self::ids($input['exam_ids'] ?? [], 'exam_ids');
        $studentIds = self::ids($input['student_ids'] ?? [], 'student_ids');
        $statuses = $input['statuses'] ?? [];

        if (! is_array($statuses) || array_filter($statuses, fn ($status) => ! is_string($status))) {
            throw ValidationException::withMessages(['statuses' => 'The selected attempt status is invalid.']);
        }

        $statuses = array_values(array_unique($statuses));

        if (array_diff($statuses, self::STATUSES)) {
            throw ValidationException::withMessages(['statuses' => 'The selected attempt status is invalid.']);
        }

        self::assertBelongToClass($examIds, $class->exams(), 'exam_ids');
        self::assertBelongToClass($studentIds, $class->students(), 'student_ids');
        $from = self::date($input['started_from'] ?? null, 'started_from');
        $until = self::date($input['started_until'] ?? null, 'started_until');

        if ($from && $until && $from > $until) {
            throw ValidationException::withMessages(['started_until' => 'The end date must be after the start date.']);
        }

        sort($statuses);

        return new self($examIds, $studentIds, $statuses, $from, $until);
    }

    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'exam_ids' => $this->examIds,
            'student_ids' => $this->studentIds,
            'statuses' => $this->statuses,
            'started_from' => $this->startedFrom,
            'started_until' => $this->startedUntil,
        ];
    }

    private static function ids(mixed $values, string $key): array
    {
        if (! is_array($values)) {
            throw ValidationException::withMessages([$key => 'The selected values are invalid.']);
        }

        foreach ($values as $value) {
            if (! is_int($value)) {
                throw ValidationException::withMessages([$key => 'The selected values are invalid.']);
            }
        }

        $ids = array_values(array_unique($values));
        sort($ids);

        return $ids;
    }

    private static function assertBelongToClass(array $ids, mixed $relation, string $key): void
    {
        if ($ids && $relation->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([$key => 'The selected values do not belong to this class.']);
        }
    }

    private static function date(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-](\d{2}):(\d{2}))$/', $value, $parts)) {
                throw new \InvalidArgumentException;
            }
            [$year, $month, $day, $hour, $minute, $second] = array_map('intval', array_slice($parts, 1, 6));
            $offsetHour = isset($parts[8]) ? (int) $parts[8] : 0;
            $offsetMinute = isset($parts[9]) ? (int) $parts[9] : 0;
            if ($year < 1 || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59
                || $offsetHour > 14 || $offsetMinute > 59 || ($offsetHour === 14 && $offsetMinute !== 0)) {
                throw new \InvalidArgumentException;
            }
            $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
            $date = CarbonImmutable::createFromFormat($format, str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value);
            $errors = CarbonImmutable::getLastErrors();
            if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                throw new \InvalidArgumentException;
            }

            return $date->utc()->toISOString();
        } catch (Throwable) {
            throw ValidationException::withMessages([$key => 'The date must be valid.']);
        }
    }
}
