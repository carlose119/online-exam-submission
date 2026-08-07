<?php

use App\Filament\Resources\ClassResource;
use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Filament\Resources\ExamResource;
use App\Filament\Resources\ExamResource\Pages\ListExams;
use App\Filament\Resources\MeetingResource;
use App\Filament\Resources\MeetingResource\Pages\ListMeetings;
use App\Filament\Resources\StudyMaterialResource;
use App\Filament\Resources\StudyMaterialResource\Pages\ListStudyMaterials;
use App\Filament\Resources\TeacherResource;
use App\Filament\Resources\TeacherResource\Pages\ListTeacher;
use App\Models\User;
use Livewire\Livewire;

it('shows the Teachers create action to an admin', function () {
    $admin = User::factory()->create(['role' => 'ADMIN']);

    $this->actingAs($admin);

    Livewire::test(ListTeacher::class)
        ->assertActionVisible('create')
        ->assertActionHasUrl('create', TeacherResource::getUrl('create'));
});

it('shows each teacher-managed create action to a teacher', function (string $page, string $resource) {
    $teacher = User::factory()->create(['role' => 'TEACHER']);

    $this->actingAs($teacher);

    Livewire::test($page)
        ->assertActionVisible('create')
        ->assertActionHasUrl('create', $resource::getUrl('create'));
})->with([
    'Classes' => [ListClasses::class, ClassResource::class],
    'Exams' => [ListExams::class, ExamResource::class],
    'Meetings' => [ListMeetings::class, MeetingResource::class],
    'Study Materials' => [ListStudyMaterials::class, StudyMaterialResource::class],
]);

it('keeps the Teachers resource and create page forbidden to teachers', function () {
    $teacher = User::factory()->create(['role' => 'TEACHER']);

    $this->actingAs($teacher);

    expect(TeacherResource::canAccess())->toBeFalse();

    $this->get(TeacherResource::getUrl())->assertForbidden();
    $this->get(TeacherResource::getUrl('create'))->assertForbidden();
});
