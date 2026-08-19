<?php

namespace App\Services;

use App\Jobs\GenerateScheduledReport;
use App\Models\ReportSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use App\Values\ReportFilters;
use App\Values\ReportScheduleTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportScheduleService
{
    public function __construct(
        private ReportScheduleTime $time,
        private ReportAccess $access,
        private ReportArtifactPublisher $publisher,
    ) {}

    public function dispatchDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $ids = ReportSchedule::query()->where('enabled', true)
            ->where('next_run_at', '<=', $now->toDateTimeString())->orderBy('id')->pluck('id');

        return $ids->sum(fn ($id): int => $this->claim((int) $id, $now));
    }

    public function execute(int $runId, ?callable $prepared = null): void
    {
        $context = $this->runContext($runId);
        if (! $context) {
            return;
        }
        [$run, $schedule, $owner, $class, $filters] = $context;
        $prepared?->__invoke();
        $this->publisher->publish(
            $class, $owner, $schedule->format, $filters, "report-run-{$runId}",
            function (?User $lockedOwner, ?SchoolClass $lockedClass) use ($runId, $run): ?bool {
                $schedule = ReportSchedule::query()->lockForUpdate()->find($run->schedule_id);
                $lockedRun = DB::table('report_runs')->lockForUpdate()->find($runId);
                if (! $lockedRun || $lockedRun->status !== 'pending') {
                    return $lockedRun?->status === 'completed' ? null : false;
                }
                [, $failure] = $this->validateRun($lockedRun, $schedule, $lockedOwner, $lockedClass);
                if ($failure) {
                    $this->skip($lockedRun, $failure);

                    return false;
                }

                return true;
            },
            fn (string $path) => DB::table('report_runs')->where('id', $runId)->where('status', 'pending')->update([
                'status' => 'completed', 'artifact_path' => $path, 'finished_at' => now(), 'updated_at' => now(),
            ]),
        );
    }

    public function create(User $actor, int $classId, array $data): ReportSchedule
    {
        return DB::transaction(function () use ($actor, $classId, $data): ReportSchedule {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $class = SchoolClass::query()->lockForUpdate()->findOrFail($classId);
            $this->access->authorize($actor, $class);

            $attributes = $this->stored($this->attributes($data, $class) + ['owner_id' => $actor->id, 'class_id' => $class->id]);
            $id = DB::table('report_schedules')->insertGetId($attributes + ['created_at' => now(), 'updated_at' => now()]);

            return ReportSchedule::query()->findOrFail($id);
        });
    }

    public function update(User $actor, int $scheduleId, int $classId, array $data): ReportSchedule
    {
        return $this->mutate($actor, $scheduleId, $classId, function (ReportSchedule $schedule, SchoolClass $class) use ($data): void {
            $this->write($schedule, $this->attributes($data, $class) + ['class_id' => $class->id]);
        });
    }

    public function setEnabled(User $actor, int $scheduleId, bool $enabled): ReportSchedule
    {
        return $this->mutate($actor, $scheduleId, null, function (ReportSchedule $schedule, SchoolClass $class) use ($enabled): void {
            $updates = ['filters' => ReportFilters::from($schedule->filters, $class)->toArray(), 'enabled' => $enabled];
            if ($enabled) {
                $updates['next_run_at'] = $this->time->next($schedule->recurrence, $schedule->weekday, substr($schedule->local_time, 0, 5), $schedule->timezone);
            }
            $this->write($schedule, $updates);
        });
    }

    public function delete(User $actor, int $scheduleId): void
    {
        $this->mutate($actor, $scheduleId, null, function (ReportSchedule $schedule, SchoolClass $class): void {
            ReportFilters::from($schedule->filters, $class);
            DB::table('report_schedules')->where('id', $schedule->id)->delete();
        });
    }

    private function mutate(User $actor, int $scheduleId, ?int $targetClassId, callable $mutation): ReportSchedule
    {
        $snapshot = ReportSchedule::query()->findOrFail($scheduleId, ['owner_id', 'class_id']);

        return DB::transaction(function () use ($actor, $scheduleId, $targetClassId, $snapshot, $mutation): ReportSchedule {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $classIds = array_values(array_unique([$snapshot->class_id, $targetClassId ?? $snapshot->class_id]));
            sort($classIds);
            $classes = SchoolClass::query()->whereKey($classIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $schedule = ReportSchedule::query()->lockForUpdate()->findOrFail($scheduleId);
            abort_unless($schedule->owner_id === $actor->id && $schedule->class_id === $snapshot->class_id, 403);
            foreach ($classes as $class) {
                $this->access->authorize($actor, $class);
            }
            $class = $classes->get($targetClassId ?? $schedule->class_id);
            abort_unless($class && $classes->count() === count($classIds), 403);
            ReportFilters::from($schedule->filters, $classes->get($schedule->class_id));
            $mutation($schedule, $class);

            return $schedule->fresh() ?? $schedule;
        });
    }

    private function claim(int $scheduleId, CarbonImmutable $now): int
    {
        $snapshot = ReportSchedule::query()->find($scheduleId, ['owner_id', 'class_id']);
        if (! $snapshot) {
            return 0;
        }

        return DB::transaction(function () use ($scheduleId, $snapshot, $now): int {
            $owner = User::query()->lockForUpdate()->find($snapshot->owner_id);
            $class = SchoolClass::query()->lockForUpdate()->find($snapshot->class_id);
            $schedule = ReportSchedule::query()->lockForUpdate()->find($scheduleId);
            if (! $owner || ! $class || ! $schedule || $schedule->owner_id !== $snapshot->owner_id
                || $schedule->class_id !== $snapshot->class_id || ! $schedule->enabled || ! is_array($schedule->filters)
                || $schedule->next_run_at->greaterThan($now)) {
                return 0;
            }
            $occurrence = $schedule->next_run_at->utc()->format('Y-m-d H:i:s');
            $inserted = DB::table('report_runs')->insertOrIgnore([
                'schedule_id' => $schedule->id, 'owner_id' => $owner->id, 'class_id' => $class->id,
                'occurrence_at' => $occurrence, 'definition_hash' => $this->definition($schedule, $schedule->filters),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) {
                return 0;
            }
            $runId = (int) DB::table('report_runs')->where('schedule_id', $schedule->id)->where('occurrence_at', $occurrence)->value('id');
            $next = $this->time->next($schedule->recurrence, $schedule->weekday, substr($schedule->local_time, 0, 5), $schedule->timezone, $now);
            DB::table('report_schedules')->where('id', $schedule->id)->update(['next_run_at' => $next->format('Y-m-d H:i:s'), 'updated_at' => now()]);
            GenerateScheduledReport::dispatch($runId)->afterCommit();

            return 1;
        });
    }

    private function runContext(int $runId): ?array
    {
        $snapshot = DB::table('report_runs')->find($runId);
        if (! $snapshot || $snapshot->status !== 'pending') {
            return null;
        }

        return DB::transaction(function () use ($runId, $snapshot): ?array {
            $owner = User::query()->lockForUpdate()->find($snapshot->owner_id);
            $class = SchoolClass::query()->lockForUpdate()->find($snapshot->class_id);
            $schedule = ReportSchedule::query()->lockForUpdate()->find($snapshot->schedule_id);
            $run = DB::table('report_runs')->lockForUpdate()->find($runId);
            if (! $run || $run->status !== 'pending') {
                return null;
            }
            [$filters, $failure] = $this->validateRun($run, $schedule, $owner, $class);
            if ($failure) {
                $this->skip($run, $failure);

                return null;
            }

            return [$run, $schedule, $owner, $class, $filters];
        });
    }

    private function validateRun(object $run, ?ReportSchedule $schedule, ?User $owner, ?SchoolClass $class): array
    {
        if (! $schedule) {
            return [null, 'schedule_missing'];
        }
        if (! $schedule->enabled) {
            return [null, 'disabled'];
        }
        if (! $owner || ! $class || (int) $schedule->owner_id !== (int) $run->owner_id
            || (int) $schedule->class_id !== (int) $run->class_id || ! $this->access->allows($owner, $class)) {
            return [null, 'unauthorized'];
        }
        if (! is_array($schedule->filters) || ! in_array($schedule->format, ['pdf', 'xlsx'], true)) {
            return [null, is_array($schedule->filters) ? 'invalid_format' : 'invalid_filters'];
        }
        try {
            $filters = ReportFilters::from($schedule->filters, $class)->toArray();
        } catch (ValidationException) {
            return [null, 'invalid_filters'];
        }
        if (! hash_equals($run->definition_hash, $this->definition($schedule, $filters))) {
            return [null, 'schedule_changed'];
        }

        return [$filters, null];
    }

    private function definition(ReportSchedule $schedule, array $filters): string
    {
        return hash('sha256', json_encode([
            $schedule->owner_id, $schedule->class_id, $schedule->format, $filters, $schedule->recurrence,
            $schedule->weekday, $schedule->local_time, $schedule->timezone, $schedule->enabled,
        ], JSON_THROW_ON_ERROR));
    }

    private function skip(object $run, string $failure): void
    {
        DB::table('report_runs')->where('id', $run->id)->where('status', 'pending')->update([
            'status' => 'skipped', 'failure_code' => $failure, 'finished_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attributes(array $data, SchoolClass $class): array
    {
        if (! in_array($data['format'] ?? null, ['pdf', 'xlsx'], true)) {
            throw ValidationException::withMessages(['format' => 'The format value is invalid.']);
        }
        $enabled = array_key_exists('enabled', $data) ? $data['enabled'] : true;
        if (! is_bool($enabled)) {
            throw ValidationException::withMessages(['enabled' => 'The enabled value must be true or false.']);
        }
        $filters = ReportFilters::from($data['filters'] ?? [], $class)->toArray();
        $next = $this->time->next($data['recurrence'] ?? '', $data['weekday'] ?? null, $data['local_time'] ?? '', $data['timezone'] ?? '');

        return [
            'format' => $data['format'], 'filters' => $filters, 'recurrence' => $data['recurrence'],
            'weekday' => $data['weekday'] ?? null, 'local_time' => $data['local_time'],
            'timezone' => $data['timezone'], 'next_run_at' => $next, 'enabled' => $enabled,
        ];
    }

    private function write(ReportSchedule $schedule, array $attributes): void
    {
        DB::table('report_schedules')->where('id', $schedule->id)->update($this->stored($attributes) + ['updated_at' => now()]);
    }

    private function stored(array $attributes): array
    {
        if (isset($attributes['filters'])) {
            $attributes['filters'] = json_encode($attributes['filters'], JSON_THROW_ON_ERROR);
        }
        if (isset($attributes['next_run_at'])) {
            $attributes['next_run_at'] = $attributes['next_run_at']->utc()->format('Y-m-d H:i:s');
        }

        return $attributes;
    }
}
