<?php

use App\Filament\Resources\ExamResource;
use App\Filament\Resources\ExamResource\Pages\ManageExamAccommodations;
use App\Models\Exam;
use App\Models\ExamAllowance;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use App\Services\ExamAllowanceService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function seedAccommodationManagement(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create([
        'title' => 'Accommodation Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ACCOM001',
    ]);
    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Accommodation Exam',
        'duration_minutes' => 60,
        'max_score' => 100,
    ]);
    $students = User::factory()->count(2)->create(['role' => 'STUDENT']);
    $class->students()->attach($students->modelKeys());

    return compact('teacher', 'class', 'exam', 'students');
}

it('lists only enrolled students with effective consumed and remaining values', function () {
    $data = seedAccommodationManagement();
    [$student] = $data['students'];
    $unenrolled = User::factory()->create(['role' => 'STUDENT']);
    ExamAllowance::create(['exam_id' => $data['exam']->id, 'student_id' => $student->id, 'additional_attempts' => 2, 'extra_time_minutes' => 15]);
    StudentAttempt::create(['exam_id' => $data['exam']->id, 'student_id' => $student->id, 'attempt_number' => 1, 'allowed_duration_minutes' => 75, 'started_at' => now(), 'finished_at' => now()]);

    $this->actingAs($data['teacher']);
    Livewire::test(ManageExamAccommodations::class, ['record' => $data['exam']->id])
        ->assertCanSeeTableRecords($data['students'])
        ->assertCanNotSeeTableRecords([$unenrolled])
        ->assertSee(['Base', 'Additional', 'Consumed', 'Remaining', 'Effective', '60 min', '15 min', '75 min']);
});

it('allows the owner to create update and revoke an enrolled student allowance', function () {
    $data = seedAccommodationManagement();
    $student = $data['students']->first();
    $this->actingAs($data['teacher']);
    $page = Livewire::test(ManageExamAccommodations::class, ['record' => $data['exam']->id]);

    $page->callTableAction('manage', $student, ['additional_attempts' => 2, 'extra_time_minutes' => 20])->assertHasNoTableActionErrors();
    $this->assertDatabaseHas('exam_allowances', ['exam_id' => $data['exam']->id, 'student_id' => $student->id, 'additional_attempts' => 2, 'extra_time_minutes' => 20]);

    $page->callTableAction('manage', $student, ['additional_attempts' => 1, 'extra_time_minutes' => 10])->assertHasNoTableActionErrors();
    $page->callTableAction('manage', $student, ['additional_attempts' => 0, 'extra_time_minutes' => 0])->assertHasNoTableActionErrors();
    $this->assertDatabaseHas('exam_allowances', ['exam_id' => $data['exam']->id, 'student_id' => $student->id, 'additional_attempts' => 0, 'extra_time_minutes' => 0]);
});

it('denies another teacher from viewing or mutating an exam allowance', function () {
    $data = seedAccommodationManagement();
    $otherTeacher = User::factory()->create(['role' => 'TEACHER']);
    $student = $data['students']->first();

    $this->actingAs($otherTeacher)
        ->get(ExamResource::getUrl('accommodations', ['record' => $data['exam']]))
        ->assertForbidden();

    expect(fn () => app(ExamAllowanceService::class)->saveForTeacher($data['exam'], $student, $otherTeacher, 1, 10))
        ->toThrow(HttpException::class);
    $this->assertDatabaseCount('exam_allowances', 0);
});

it('rechecks enrollment inside the teacher mutation boundary', function () {
    $data = seedAccommodationManagement();
    $unenrolled = User::factory()->create(['role' => 'STUDENT']);

    expect(fn () => app(ExamAllowanceService::class)->saveForTeacher($data['exam'], $unenrolled, $data['teacher'], 1, 10))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('exam_allowances', 0);
});

it('rejects allowance values above the domain limits before persistence', function () {
    $data = seedAccommodationManagement();
    $student = $data['students']->first();
    $this->actingAs($data['teacher']);

    Livewire::test(ManageExamAccommodations::class, ['record' => $data['exam']->id])
        ->callTableAction('manage', $student, [
            'additional_attempts' => ExamAllowanceService::MAX_ADDITIONAL_ATTEMPTS + 1,
            'extra_time_minutes' => ExamAllowanceService::MAX_EXTRA_TIME_MINUTES + 1,
        ])
        ->assertHasTableActionErrors(['additional_attempts', 'extra_time_minutes']);

    foreach ([[ExamAllowanceService::MAX_ADDITIONAL_ATTEMPTS + 1, 0], [0, ExamAllowanceService::MAX_EXTRA_TIME_MINUTES + 1]] as $values) {
        expect(fn () => app(ExamAllowanceService::class)->save($data['exam'], $student, ...$values))
            ->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('exam_allowances', 0);
});

it('preserves consumed attempts and existing duration snapshots when reducing an allowance', function () {
    $data = seedAccommodationManagement();
    $student = $data['students']->first();
    $service = app(ExamAllowanceService::class);
    $service->saveForTeacher($data['exam'], $student, $data['teacher'], 2, 30);
    foreach ([1, 2] as $number) {
        StudentAttempt::create(['exam_id' => $data['exam']->id, 'student_id' => $student->id, 'attempt_number' => $number, 'allowed_duration_minutes' => 90, 'started_at' => now(), 'finished_at' => now()]);
    }

    expect(fn () => $service->saveForTeacher($data['exam'], $student, $data['teacher'], 0, 0))
        ->toThrow(ValidationException::class);
    $service->saveForTeacher($data['exam'], $student, $data['teacher'], 1, 0);

    expect(StudentAttempt::where('student_id', $student->id)->pluck('allowed_duration_minutes')->all())->toBe([90, 90])
        ->and(StudentAttempt::where('student_id', $student->id)->count())->toBe(2);
});
