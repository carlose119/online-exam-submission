<?php

use App\Models\Exam;
use App\Models\ReportSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ReportScheduleService;
use App\Values\ReportFilters;
use App\Values\ReportScheduleTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function scheduleFixture(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $other = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Owned', 'teacher_id' => $teacher->id, 'invitation_code' => 'SCHD0001']);
    $foreign = SchoolClass::create(['title' => 'Foreign', 'teacher_id' => $other->id, 'invitation_code' => 'SCHD0002']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Exam', 'duration_minutes' => 60, 'max_score' => 100]);
    $foreignExam = Exam::create(['class_id' => $foreign->id, 'title' => 'Other', 'duration_minutes' => 60, 'max_score' => 100]);

    return compact('teacher', 'other', 'class', 'foreign', 'exam', 'foreignExam');
}

function scheduleData(array $replace = []): array
{
    return array_replace([
        'format' => 'pdf', 'filters' => ReportFilters::EMPTY, 'recurrence' => 'daily',
        'weekday' => null, 'local_time' => '09:30', 'timezone' => 'America/New_York', 'enabled' => true,
    ], $replace);
}

it('calculates deterministic daily, weekly, and DST wall times in UTC', function () {
    $time = app(ReportScheduleTime::class);

    expect($time->next('daily', null, '09:30', 'America/New_York', CarbonImmutable::parse('2026-01-15 13:00Z'))->toISOString())
        ->toBe('2026-01-15T14:30:00.000000Z')
        ->and($time->next('weekly', 1, '09:30', 'UTC', CarbonImmutable::parse('2026-01-13 00:00Z'))->toISOString())
        ->toBe('2026-01-19T09:30:00.000000Z')
        ->and($time->next('daily', null, '02:30', 'America/New_York', CarbonImmutable::parse('2026-03-08 00:00Z'))->toISOString())
        ->toBe('2026-03-09T06:30:00.000000Z')
        ->and($time->next('daily', null, '01:30', 'America/New_York', CarbonImmutable::parse('2026-11-01 00:00Z'))->toISOString())
        ->toBe('2026-11-01T05:30:00.000000Z');
});

it('creates, updates, disables, enables, and deletes through the boundary', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 13:00Z'));
    $data = scheduleFixture();
    $service = app(ReportScheduleService::class);
    $schedule = $service->create($data['teacher'], $data['class']->id, scheduleData(['filters' => [...ReportFilters::EMPTY, 'exam_ids' => [$data['exam']->id]]]));

    expect($schedule->owner_id)->toBe($data['teacher']->id)
        ->and($schedule->filters['exam_ids'])->toBe([$data['exam']->id])
        ->and($schedule->next_run_at->toISOString())->toBe('2026-01-15T14:30:00.000000Z');

    $schedule = $service->update($data['teacher'], $schedule->id, $data['class']->id, scheduleData([
        'format' => 'xlsx', 'recurrence' => 'weekly', 'weekday' => 5, 'local_time' => '10:00', 'timezone' => 'UTC',
    ]));
    expect($schedule->format)->toBe('xlsx')->and($schedule->next_run_at->toISOString())->toBe('2026-01-16T10:00:00.000000Z');

    $preserved = $service->setEnabled($data['teacher'], $schedule->id, false)->next_run_at;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-17 00:00Z'));
    expect($service->setEnabled($data['teacher'], $schedule->id, true)->next_run_at->greaterThan($preserved))->toBeTrue();
    $service->delete($data['teacher'], $schedule->id);
    $this->assertDatabaseMissing('report_schedules', ['id' => $schedule->id]);
});

it('rejects invalid schedule inputs and cross-class filters', function (array $replace) {
    $data = scheduleFixture();
    expect(fn () => app(ReportScheduleService::class)->create($data['teacher'], $data['class']->id, scheduleData($replace)))
        ->toThrow(ValidationException::class);
})->with([
    'format' => [['format' => 'csv']], 'timezone' => [['timezone' => 'Mars/Base']],
    'time' => [['local_time' => '24:00']], 'recurrence' => [['recurrence' => 'monthly']],
    'weekly day' => [['recurrence' => 'weekly']], 'daily day' => [['weekday' => 1]],
    'enabled string' => [['enabled' => 'false']], 'enabled integer' => [['enabled' => 0]], 'enabled null' => [['enabled' => null]],
]);

it('rejects cross-class ids, reassignment, role revocation, and non-owner tampering', function () {
    $data = scheduleFixture();
    $service = app(ReportScheduleService::class);
    expect(fn () => $service->create($data['teacher'], $data['class']->id, scheduleData([
        'filters' => [...ReportFilters::EMPTY, 'exam_ids' => [$data['foreignExam']->id]],
    ])))->toThrow(ValidationException::class);

    $schedule = $service->create($data['teacher'], $data['class']->id, scheduleData());
    expect(fn () => $service->update($data['teacher'], $schedule->id, $data['foreign']->id, scheduleData()))->toThrow(HttpException::class)
        ->and(fn () => $service->setEnabled($data['other'], $schedule->id, false))->toThrow(HttpException::class);

    DB::table('report_schedules')->where('id', $schedule->id)->update(['filters' => json_encode([...ReportFilters::EMPTY, 'exam_ids' => [$data['foreignExam']->id]])]);
    expect(fn () => $service->setEnabled($data['teacher'], $schedule->id, false))->toThrow(ValidationException::class);
    DB::table('report_schedules')->where('id', $schedule->id)->update(['filters' => json_encode(ReportFilters::EMPTY)]);

    $data['teacher']->update(['role' => 'STUDENT']);
    expect(fn () => $service->delete($data['teacher'], $schedule->id))->toThrow(HttpException::class);
    $data['teacher']->update(['role' => 'TEACHER']);
    $data['class']->update(['teacher_id' => $data['other']->id]);
    expect(fn () => $service->setEnabled($data['teacher'], $schedule->id, false))->toThrow(HttpException::class);
    expect(ReportSchedule::find($schedule->id))->not->toBeNull();
});

it('keeps admin-created schedules owned by that admin', function () {
    $data = scheduleFixture();
    $admin = User::factory()->create(['role' => 'ADMIN']);
    $schedule = app(ReportScheduleService::class)->create($admin, $data['foreign']->id, scheduleData());

    expect($schedule->owner_id)->toBe($admin->id);
});

it('rejects invalid recurrence SQL', function () {
    $data = scheduleFixture();
    $schedule = app(ReportScheduleService::class)->create($data['teacher'], $data['class']->id, scheduleData());

    foreach ([['daily', 1], ['weekly', null], ['weekly', 8]] as [$recurrence, $weekday]) {
        expect(fn () => DB::table('report_schedules')->where('id', $schedule->id)->update(['recurrence' => $recurrence, 'weekday' => $weekday]))
            ->toThrow(QueryException::class);
    }
});

it('keeps the UTC wall value under a non-UTC database session', function () {
    if (DB::getDriverName() !== 'mysql') {
        expect(true)->toBeTrue();

        return;
    }
    DB::statement("SET time_zone = '+05:00'");
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 13:00Z'));
    $data = scheduleFixture();
    $schedule = app(ReportScheduleService::class)->create($data['teacher'], $data['class']->id, scheduleData());

    expect(DB::table('report_schedules')->where('id', $schedule->id)->value('next_run_at'))->toBe('2026-01-15 14:30:00')
        ->and($schedule->next_run_at->toISOString())->toBe('2026-01-15T14:30:00.000000Z');
    DB::statement("SET time_zone = '+00:00'");
});
