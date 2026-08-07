<?php

use App\Enums\StudyMaterialType;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    config([
        'study-materials.disk' => 'public',
        'study-materials.path_prefix' => 'materials',
    ]);
});

function reconciliationClass(string $suffix): SchoolClass
{
    $teacher = User::create([
        'name' => "Reconciliation Teacher {$suffix}",
        'email' => "reconciliation-{$suffix}@example.com",
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    return SchoolClass::create([
        'title' => "Reconciliation Class {$suffix}",
        'teacher_id' => $teacher->id,
        'invitation_code' => "REC{$suffix}",
    ]);
}

function referencedMaterial(SchoolClass $class, string $path): StudyMaterial
{
    return StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'Referenced material',
        'type' => StudyMaterialType::File,
        'file_path_or_url' => $path,
    ]);
}

it('discovers historical orphans in dry-run without exposing or deleting them', function () {
    $path = 'materials/private-historical.pdf';
    Storage::disk('public')->put($path, 'orphan');

    $this->artisan('materials:reconcile')
        ->expectsOutput('Mode: dry-run')
        ->expectsOutput('Summary: scanned=1 active=0 orphaned=1 deleted=0 skipped=1 failed=0')
        ->doesntExpectOutputToContain($path)
        ->assertSuccessful();

    Storage::disk('public')->assertExists($path);
});

it('deletes only orphaned managed files and preserves active references', function () {
    $active = 'materials/active.pdf';
    Storage::disk('public')->put($active, 'active');
    Storage::disk('public')->put('materials/orphan.pdf', 'orphan');
    Storage::disk('public')->put('materials/nested/orphan.pdf', 'nested');
    Storage::disk('public')->put('outside/orphan.pdf', 'outside');
    referencedMaterial(reconciliationClass('active'), $active);

    $this->artisan('materials:reconcile', ['--delete' => true])
        ->expectsOutput('Summary: scanned=3 active=1 orphaned=2 deleted=2 skipped=0 failed=0')
        ->assertSuccessful();

    Storage::disk('public')->assertExists($active);
    Storage::disk('public')->assertExists('outside/orphan.pdf');
    Storage::disk('public')->assertMissing('materials/orphan.pdf');
    Storage::disk('public')->assertMissing('materials/nested/orphan.pdf');
});

it('finds and removes a file left by a class database cascade', function () {
    $class = reconciliationClass('cascade');
    $path = 'materials/cascade.pdf';
    referencedMaterial($class, $path);
    Storage::disk('public')->put($path, 'cascade');

    $class->delete();

    $this->assertDatabaseMissing('study_materials', ['file_path_or_url' => $path]);
    Storage::disk('public')->assertExists($path);
    $this->artisan('materials:reconcile', ['--delete' => true])
        ->expectsOutput('Summary: scanned=1 active=0 orphaned=1 deleted=1 skipped=0 failed=0')
        ->assertSuccessful();
    Storage::disk('public')->assertMissing($path);
});

it('honors a configured prefix boundary and scans nested files', function () {
    config(['study-materials.path_prefix' => 'tenant/materials']);
    Storage::disk('public')->put('tenant/materials/nested/orphan.pdf', 'managed');
    Storage::disk('public')->put('tenant/materials-other/orphan.pdf', 'lookalike');

    $this->artisan('materials:reconcile')
        ->expectsOutput('Summary: scanned=1 active=0 orphaned=1 deleted=0 skipped=1 failed=0')
        ->assertSuccessful();
});

it('requires force for production deletion', function () {
    app()->detectEnvironment(fn (): string => 'production');
    Storage::disk('public')->put('materials/production.pdf', 'orphan');

    $this->artisan('materials:reconcile', ['--delete' => true])
        ->expectsOutput('Mode: delete')
        ->expectsOutput('Deletion in production requires --force. No files were changed.')
        ->assertFailed();
    Storage::disk('public')->assertExists('materials/production.pdf');

    $this->artisan('materials:reconcile', ['--delete' => true, '--force' => true])
        ->expectsOutput('Summary: scanned=1 active=0 orphaned=1 deleted=1 skipped=0 failed=0')
        ->assertSuccessful();
});

it('returns a failed count and non-zero exit when listing fails', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('allFiles')->once()->with('materials')
        ->andThrow(new RuntimeException('sensitive listing detail'));
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    $this->artisan('materials:reconcile')
        ->expectsOutput('Summary: scanned=0 active=0 orphaned=0 deleted=0 skipped=0 failed=1')
        ->doesntExpectOutputToContain('sensitive listing detail')
        ->assertFailed();
});

it('returns a failed count and non-zero exit when deletion fails', function () {
    $path = 'materials/private-failure.pdf';
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('allFiles')->once()->with('materials')->andReturn([$path]);
    $disk->shouldReceive('exists')->once()->with($path)->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with($path)->andThrow(new RuntimeException('secret deletion detail'));
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    $this->artisan('materials:reconcile', ['--delete' => true])
        ->expectsOutput('Summary: scanned=1 active=0 orphaned=1 deleted=0 skipped=0 failed=1')
        ->doesntExpectOutputToContain($path)
        ->doesntExpectOutputToContain('secret deletion detail')
        ->assertFailed();
});
