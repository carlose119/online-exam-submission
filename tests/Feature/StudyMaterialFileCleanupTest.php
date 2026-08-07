<?php

use App\Enums\StudyMaterialType;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use App\Models\User;
use App\Services\StudyMaterialFileCleanup;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    config([
        'study-materials.disk' => 'public',
        'study-materials.path_prefix' => 'materials',
    ]);
});

function cleanupClass(string $suffix): SchoolClass
{
    $teacher = User::create([
        'name' => "Cleanup Teacher {$suffix}",
        'email' => "cleanup-{$suffix}@example.com",
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    return SchoolClass::create([
        'title' => "Cleanup Class {$suffix}",
        'teacher_id' => $teacher->id,
        'invitation_code' => "CLEAN{$suffix}",
    ]);
}

function cleanupMaterial(SchoolClass $class, string $path, string $title = 'Material'): StudyMaterial
{
    return StudyMaterial::create([
        'class_id' => $class->id,
        'title' => $title,
        'type' => StudyMaterialType::File,
        'file_path_or_url' => $path,
    ]);
}

it('deletes an unreferenced replacement only after commit', function () {
    $material = cleanupMaterial(cleanupClass('replace'), 'materials/old.pdf');
    Storage::disk('public')->put('materials/old.pdf', 'old');
    Storage::disk('public')->put('materials/new.pdf', 'new');

    DB::beginTransaction();
    $material->update(['file_path_or_url' => 'materials/new.pdf']);
    Storage::disk('public')->assertExists('materials/old.pdf');

    DB::commit();

    Storage::disk('public')->assertMissing('materials/old.pdf');
    Storage::disk('public')->assertExists('materials/new.pdf');
});

it('deletes the obsolete file when changing to a non-file type', function (StudyMaterialType $type) {
    $material = cleanupMaterial(cleanupClass($type->value), 'materials/old.pdf');
    Storage::disk('public')->put('materials/old.pdf', 'old');

    DB::beginTransaction();
    $material->update(['type' => $type, 'file_path_or_url' => 'https://example.com/resource']);
    DB::commit();

    Storage::disk('public')->assertMissing('materials/old.pdf');
})->with([StudyMaterialType::Link, StudyMaterialType::Meeting]);

it('preserves a shared file until its final global reference is deleted', function () {
    $path = 'materials/shared.pdf';
    $first = cleanupMaterial(cleanupClass('shared-a'), $path, 'First');
    $second = cleanupMaterial(cleanupClass('shared-b'), $path, 'Second');
    Storage::disk('public')->put($path, 'shared');

    DB::beginTransaction();
    $first->delete();
    DB::commit();
    Storage::disk('public')->assertExists($path);

    $second->delete();
    Storage::disk('public')->assertMissing($path);
});

it('discards cleanup when the database transaction rolls back', function () {
    $material = cleanupMaterial(cleanupClass('rollback'), 'materials/old.pdf');
    Storage::disk('public')->put('materials/old.pdf', 'old');

    DB::beginTransaction();
    $material->update(['file_path_or_url' => 'materials/new.pdf']);
    DB::rollBack();

    Storage::disk('public')->assertExists('materials/old.pdf');
    $this->assertDatabaseHas('study_materials', [
        'id' => $material->id,
        'file_path_or_url' => 'materials/old.pdf',
    ]);
});

it('rejects unmanaged and unsafe paths without touching storage', function (string $candidate) {
    Storage::disk('public')->put('materials/protected.pdf', 'protected');
    Storage::disk('public')->put('outside/protected.pdf', 'outside');

    expect(app(StudyMaterialFileCleanup::class)->cleanup($candidate))
        ->toBe(StudyMaterialFileCleanup::REJECTED);
    Storage::disk('public')->assertExists('materials/protected.pdf');
    Storage::disk('public')->assertExists('outside/protected.pdf');
})->with([
    'blank' => '',
    'outside prefix' => 'outside/protected.pdf',
    'prefix lookalike' => 'materialsx/protected.pdf',
    'traversal' => 'materials/../outside/protected.pdf',
    'absolute Unix path' => '/materials/protected.pdf',
    'absolute Windows path' => 'C:\\materials\\protected.pdf',
    'alternate separator' => 'materials\\protected.pdf',
]);

it('reports a managed file that is already missing', function () {
    expect(app(StudyMaterialFileCleanup::class)->cleanup('materials/missing.pdf'))
        ->toBe(StudyMaterialFileCleanup::MISSING);
});

it('returns deterministic outcomes for referenced and deleted files', function () {
    $path = 'materials/outcome.pdf';
    $material = cleanupMaterial(cleanupClass('outcome'), $path);
    Storage::disk('public')->put($path, 'outcome');
    $cleanup = app(StudyMaterialFileCleanup::class);

    expect($cleanup->cleanup($path))->toBe(StudyMaterialFileCleanup::STILL_REFERENCED);

    $material->deleteQuietly();
    expect($cleanup->cleanup($path))->toBe(StudyMaterialFileCleanup::DELETED);
    Storage::disk('public')->assertMissing($path);
});

it('keeps committed database changes and logs storage deletion failures', function (bool $throws) {
    $material = cleanupMaterial(cleanupClass($throws ? 'exception' : 'false'), 'materials/old.pdf');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('materials/old.pdf')->andReturnTrue();
    $delete = $disk->shouldReceive('delete')->once()->with('materials/old.pdf');
    $throws ? $delete->andThrow(new RuntimeException('secret adapter detail')) : $delete->andReturnFalse();
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);
    Log::spy();

    DB::beginTransaction();
    $material->update(['file_path_or_url' => 'materials/new.pdf']);
    DB::commit();

    $this->assertDatabaseHas('study_materials', [
        'id' => $material->id,
        'file_path_or_url' => 'materials/new.pdf',
    ]);
    Log::shouldHaveReceived('warning')->once()->with(
        'Study material file cleanup failed.',
        Mockery::on(fn (array $context): bool => $context['outcome'] === StudyMaterialFileCleanup::FAILED
            && $context['path_hash'] === hash('sha256', 'materials/old.pdf')
            && ! str_contains(json_encode($context), 'secret adapter detail')),
    );
})->with(['false result' => false, 'exception' => true]);

it('does not delete an active file after an unrelated update', function () {
    $material = cleanupMaterial(cleanupClass('unrelated'), 'materials/active.pdf');
    Storage::disk('public')->put('materials/active.pdf', 'active');

    $material->update(['title' => 'Renamed']);

    Storage::disk('public')->assertExists('materials/active.pdf');
});
