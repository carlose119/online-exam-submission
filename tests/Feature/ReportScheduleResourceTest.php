<?php

use App\Filament\Resources\ReportScheduleResource;
use App\Filament\Resources\ReportScheduleResource\Pages\CreateReportSchedule;
use App\Filament\Resources\ReportScheduleResource\Pages\EditReportSchedule;
use App\Filament\Resources\ReportScheduleResource\Pages\ListReportSchedules;
use App\Models\Exam;
use App\Models\ReportSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ReportScheduleService;
use App\Values\ReportFilters;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function scheduleUiFixture(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $other = User::factory()->create(['role' => 'TEACHER']);
    $admin = User::factory()->create(['role' => 'ADMIN']);
    $class = SchoolClass::create(['title' => 'Owned', 'teacher_id' => $teacher->id, 'invitation_code' => 'UI000001']);
    $foreign = SchoolClass::create(['title' => 'Foreign', 'teacher_id' => $other->id, 'invitation_code' => 'UI000002']);
    $exam = Exam::create(['class_id' => $class->id, 'title' => 'Owned exam', 'duration_minutes' => 30, 'max_score' => 10]);
    $foreignExam = Exam::create(['class_id' => $foreign->id, 'title' => 'Foreign exam', 'duration_minutes' => 30, 'max_score' => 10]);
    $student = User::factory()->create(['role' => 'STUDENT']);
    $foreignStudent = User::factory()->create(['role' => 'STUDENT']);
    $class->students()->attach($student);
    $foreign->students()->attach($foreignStudent);

    return compact('teacher', 'other', 'admin', 'class', 'foreign', 'exam', 'foreignExam', 'student', 'foreignStudent');
}

function scheduleUiData(SchoolClass $class, array $replace = []): array
{
    return array_replace_recursive([
        'class_id' => (string) $class->id, 'format' => 'pdf',
        'filters' => ['exam_ids' => [], 'student_ids' => [], 'statuses' => [], 'started_from' => null, 'started_until' => null],
        'recurrence' => 'daily', 'weekday' => null, 'local_time' => '09:30', 'timezone' => 'UTC', 'enabled' => true,
    ], $replace);
}

it('creates, edits, toggles, and deletes an owner schedule through Filament', function () {
    $f = scheduleUiFixture();
    $this->actingAs($f['teacher']);
    Livewire::test(CreateReportSchedule::class)->fillForm(scheduleUiData($f['class'], [
        'filters' => ['exam_ids' => [(string) $f['exam']->id], 'student_ids' => [(string) $f['student']->id]],
    ]))->call('create')->assertHasNoErrors();
    $schedule = ReportSchedule::query()->firstOrFail();
    expect($schedule->owner_id)->toBe($f['teacher']->id)->and($schedule->filters['exam_ids'])->toBe([$f['exam']->id]);

    Livewire::test(EditReportSchedule::class, ['record' => $schedule->id])->fillForm([
        'format' => 'xlsx', 'recurrence' => 'weekly', 'weekday' => '5', 'local_time' => '10:00',
    ])->call('save')->assertHasNoErrors();
    expect($schedule->fresh()->format)->toBe('xlsx')->and($schedule->fresh()->weekday)->toBe(5);

    Livewire::test(ListReportSchedules::class)->callTableAction('toggle', $schedule);
    expect($schedule->fresh()->enabled)->toBeFalse();
    Livewire::test(ListReportSchedules::class)->callTableAction('delete', $schedule);
    $this->assertDatabaseMissing('report_schedules', ['id' => $schedule->id]);
});

it('scopes classes and schedules for teachers and admins and denies another owner', function () {
    $f = scheduleUiFixture();
    $schedule = app(ReportScheduleService::class)->create($f['teacher'], $f['class']->id, scheduleUiData($f['class'], ['filters' => ReportFilters::EMPTY]));
    $this->actingAs($f['teacher']);
    expect(ReportScheduleResource::classOptions())->toHaveKey($f['class']->id)->not->toHaveKey($f['foreign']->id);
    $this->actingAs($f['admin']);
    expect(ReportScheduleResource::classOptions())->toHaveKeys([$f['class']->id, $f['foreign']->id])
        ->and(ReportScheduleResource::getEloquentQuery()->count())->toBe(0);
    $this->actingAs($f['other'])->get(ReportScheduleResource::getUrl('edit', ['record' => $schedule]))->assertForbidden();
});

it('does not leak dependent options and rechecks changed class authority', function () {
    $f = scheduleUiFixture();
    $this->actingAs($f['teacher']);
    expect(ReportScheduleResource::examOptions($f['class']->id))->toHaveKey($f['exam']->id)->not->toHaveKey($f['foreignExam']->id)
        ->and(ReportScheduleResource::studentOptions($f['class']->id))->toHaveKey($f['student']->id)->not->toHaveKey($f['foreignStudent']->id);
    $f['class']->update(['teacher_id' => $f['other']->id]);
    expect(ReportScheduleResource::examOptions($f['class']->id))->toBe([])
        ->and(fn () => ReportScheduleResource::input(scheduleUiData($f['class'])))->toThrow(HttpException::class);
});

it('fails closed when owner, class authority, or role changes after mount', function () {
    $f = scheduleUiFixture();
    $service = app(ReportScheduleService::class);
    $schedule = $service->create($f['teacher'], $f['class']->id, scheduleUiData($f['class'], ['filters' => ReportFilters::EMPTY]));
    $this->actingAs($f['teacher']);
    $edit = Livewire::test(EditReportSchedule::class, ['record' => $schedule->id]);
    DB::table('report_schedules')->where('id', $schedule->id)->update(['owner_id' => $f['other']->id]);
    $edit->fillForm(['format' => 'xlsx'])->call('save')->assertForbidden();

    DB::table('report_schedules')->where('id', $schedule->id)->update(['owner_id' => $f['teacher']->id]);
    $f['class']->update(['teacher_id' => $f['other']->id]);
    expect(fn () => Livewire::test(ListReportSchedules::class)->callTableAction('toggle', $schedule))
        ->toThrow(ActionNotResolvableException::class);
    $f['class']->update(['teacher_id' => $f['teacher']->id]);
    $f['teacher']->update(['role' => 'STUDENT']);
    expect(fn () => Livewire::test(ListReportSchedules::class)->callTableAction('delete', $schedule))
        ->toThrow(ActionNotResolvableException::class);
    expect($schedule->fresh())->not->toBeNull();
});

it('rejects manipulated Filament values and invalid domain inputs', function () {
    $f = scheduleUiFixture();
    $this->actingAs($f['teacher']);
    expect(fn () => ReportScheduleResource::input(scheduleUiData($f['class'], ['class_id' => '1e2'])))->toThrow(ValidationException::class)
        ->and(fn () => ReportScheduleResource::input(scheduleUiData($f['class'], ['enabled' => 'true'])))->toThrow(ValidationException::class);
    Livewire::test(CreateReportSchedule::class)->fillForm(scheduleUiData($f['class'], ['timezone' => 'Mars/Base']))
        ->call('create')->assertHasErrors();
    Livewire::test(CreateReportSchedule::class)->fillForm(scheduleUiData($f['class'], [
        'filters' => ['exam_ids' => [(string) $f['foreignExam']->id]],
    ]))->call('create')->assertHasErrors();
    expect(ReportSchedule::query()->count())->toBe(0);
});
