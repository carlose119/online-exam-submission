<?php

use App\Enums\StudyMaterialType;
use App\Filament\Resources\StudyMaterialResource\Pages\CreateStudyMaterial;
use App\Filament\Resources\StudyMaterialResource\Pages\ListStudyMaterials;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use App\Models\User;
use App\Services\StudyMaterialStorageQuota;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    config([
        'study-materials.disk' => 'public',
        'study-materials.teacher_quota_bytes' => 100,
    ]);
});

function quotaTeacher(string $suffix): User
{
    return User::create([
        'name' => "Quota Teacher {$suffix}",
        'email' => "quota-{$suffix}@example.com",
        'password' => 'password',
        'role' => 'TEACHER',
    ]);
}

function quotaClass(User $teacher, string $suffix): SchoolClass
{
    return SchoolClass::create([
        'title' => "Quota Class {$suffix}",
        'teacher_id' => $teacher->id,
        'invitation_code' => "QUOTA{$suffix}",
    ]);
}

function quotaMaterial(SchoolClass $class, string $path, string $title = 'Quota file'): StudyMaterial
{
    return StudyMaterial::create([
        'class_id' => $class->id,
        'title' => $title,
        'type' => StudyMaterialType::File,
        'file_path_or_url' => $path,
    ]);
}

it('accepts the exact quota and rejects an excess with usage feedback', function () {
    $teacher = quotaTeacher('exact');
    $class = quotaClass($teacher, 'EXACT001');
    Storage::disk('public')->put('materials/existing.pdf', str_repeat('x', 60));
    quotaMaterial($class, 'materials/existing.pdf');
    $quota = app(StudyMaterialStorageQuota::class);

    expect($quota->violation($teacher->id, $class->id, 40))->toBeNull()
        ->and($quota->violation($teacher->id, $class->id, 41))
        ->toBe('Storage quota exceeded. Used: 60 B; limit: 100 B; remaining: 40 B. Upload a smaller file or ask an administrator to increase the quota.');
});

it('isolates usage per teacher and rejects an unowned class', function () {
    $teacherA = quotaTeacher('isolation-a');
    $teacherB = quotaTeacher('isolation-b');
    $classA = quotaClass($teacherA, 'ISOLA001');
    $classB = quotaClass($teacherB, 'ISOLB001');
    Storage::disk('public')->put('materials/a.pdf', str_repeat('a', 70));
    Storage::disk('public')->put('materials/b.pdf', str_repeat('b', 30));
    quotaMaterial($classA, 'materials/a.pdf');
    quotaMaterial($classB, 'materials/b.pdf');
    $quota = app(StudyMaterialStorageQuota::class);

    expect($quota->usage($teacherA->id)['used'])->toBe(70)
        ->and($quota->usage($teacherB->id)['used'])->toBe(30);
    expect(fn () => $quota->violation($teacherA->id, $classB->id, 1))
        ->toThrow(AuthorizationException::class, 'You do not own the selected class.');
});

it('counts shared paths once and missing files as zero', function () {
    $teacher = quotaTeacher('shared');
    $classA = quotaClass($teacher, 'SHARA001');
    $classB = quotaClass($teacher, 'SHARB001');
    Storage::disk('public')->put('materials/shared.pdf', str_repeat('s', 55));
    quotaMaterial($classA, 'materials/shared.pdf', 'Shared A');
    quotaMaterial($classB, 'materials/shared.pdf', 'Shared B');
    quotaMaterial($classA, 'materials/missing.pdf', 'Missing');

    expect(app(StudyMaterialStorageQuota::class)->usage($teacher->id)['used'])->toBe(55);
});

it('credits a sole replacement but not a shared path', function () {
    $teacher = quotaTeacher('replacement');
    $class = quotaClass($teacher, 'REPLACE1');
    Storage::disk('public')->put('materials/old.pdf', str_repeat('o', 80));
    $original = quotaMaterial($class, 'materials/old.pdf', 'Original');
    $quota = app(StudyMaterialStorageQuota::class);

    expect($quota->violation($teacher->id, $class->id, 100, $original))->toBeNull();

    quotaMaterial($class, 'materials/old.pdf', 'Shared reference');
    expect($quota->violation($teacher->id, $class->id, 21, $original))->not->toBeNull();
});

it('rejects an over-quota temporary upload before permanent storage', function () {
    $teacher = quotaTeacher('upload');
    $class = quotaClass($teacher, 'UPLOAD01');
    Storage::disk('public')->put('materials/existing.pdf', str_repeat('x', 100));
    quotaMaterial($class, 'materials/existing.pdf');

    $this->actingAs($teacher);
    $component = Livewire::test(CreateStudyMaterial::class)
        ->set('data.class_id', $class->id)
        ->set('data.type', StudyMaterialType::File->value)
        ->set('data.title', 'Blocked upload')
        ->set('data.uploaded_file', UploadedFile::fake()->createWithContent('blocked.pdf', "%PDF-1.4\n".str_repeat('x', 1024)));

    $component->call('create')->assertHasFormErrors(['uploaded_file']);

    expect(Storage::disk('public')->allFiles("materials/{$class->id}"))->toBeEmpty()
        ->and(StudyMaterial::where('title', 'Blocked upload')->exists())->toBeFalse();
});

it('shows authorized usage on the study materials list', function () {
    $teacher = quotaTeacher('display');
    $class = quotaClass($teacher, 'DISPLAY1');
    Storage::disk('public')->put('materials/display.pdf', str_repeat('d', 25));
    quotaMaterial($class, 'materials/display.pdf');

    $this->actingAs($teacher);
    Livewire::test(ListStudyMaterials::class)
        ->assertSee('Storage used: 25 B of 100 B. Remaining: 75 B.');
});
