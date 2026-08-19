<?php

namespace App\Services;

use App\Models\ReportSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use App\Values\ReportFilters;
use App\Values\ReportScheduleTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportScheduleService
{
    public function __construct(private ReportScheduleTime $time, private ReportAccess $access) {}

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
