<?php

use App\Exceptions\ReportArtifactCleanupException;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ClassReportService;
use App\Services\ReportAccess;
use App\Services\ReportArtifactPublisher;
use App\Services\ReportFormatService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function publisherFixture(bool $authorized = false): array
{
    $user = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create([
        'title' => 'Publisher failure',
        'teacher_id' => $user->id,
        'invitation_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
    ]);
    $reports = Mockery::mock(ClassReportService::class);
    $reports->shouldReceive('generate')->once()->andReturn([]);
    $formats = Mockery::mock(ReportFormatService::class);
    $formats->shouldReceive('toPdf')->once()->andReturnUsing(fn ($data, $record, $path) => $path);
    $access = Mockery::mock(ReportAccess::class);
    $access->shouldReceive('allows')->andReturn($authorized);

    return [$class, $user, new ReportArtifactPublisher($reports, $formats, $access), $formats];
}

it('fails visibly when denied staging cleanup returns false', function () {
    [$class, $user, $publisher] = publisherFixture();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('#^staging/.+\.pdf$#'))->andReturnTrue();
    $disk->shouldReceive('delete')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('reports')->andReturn($disk);
    Log::spy();

    expect(fn () => $publisher->publish($class, $user, 'pdf'))
        ->toThrow(ReportArtifactCleanupException::class, 'staging/');
    Log::shouldHaveReceived('error')->once()->with(
        Mockery::pattern('/Report artifact cleanup failed/'),
        Mockery::on(fn (array $context): bool => str_starts_with($context['path'], 'staging/')),
    );
});

it('cleans staging and propagates a move failure', function () {
    [$class, $user, $publisher, $formats] = publisherFixture(true);
    $formats->shouldReceive('filename')->once()->andReturn('class-'.$class->id.'-failed.pdf');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('move')->once()->andReturnFalse();
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('#^staging/#'))->andReturnTrue();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('disk')->with('reports')->andReturn($disk);

    expect(fn () => $publisher->publish($class, $user, 'pdf'))
        ->toThrow(RuntimeException::class, 'Unable to publish report artifact.');
});

it('fails visibly when notification rollback canonical cleanup returns false', function () {
    [$class, $user, $publisher, $formats] = publisherFixture(true);
    $filename = 'class-'.$class->id.'-orphan.pdf';
    $formats->shouldReceive('filename')->once()->andReturn($filename);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('move')->once()->andReturnTrue();
    $disk->shouldReceive('exists')->once()->with($filename)->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with($filename)->andReturnFalse();
    Storage::shouldReceive('disk')->with('reports')->andReturn($disk);
    Log::spy();
    Schema::drop('notifications');

    expect(fn () => $publisher->publish($class, $user, 'pdf'))
        ->toThrow(ReportArtifactCleanupException::class, $filename);
    Log::shouldHaveReceived('error')->once()->with(
        Mockery::pattern('/Report artifact cleanup failed/'),
        Mockery::on(fn (array $context): bool => $context['path'] === $filename),
    );
});

it('reports a throwing cleanup adapter without exposing malicious diagnostics', function () {
    [$class, $user, $publisher] = publisherFixture();
    $malicious = "Adapter failed at C:\\backend\\reports\\secret.pdf via https://storage.example.test/private?token=super-secret\r\nFORGED LOG ENTRY";
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->andReturnTrue();
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException($malicious));
    Storage::shouldReceive('disk')->with('reports')->andReturn($disk);
    Log::spy();

    try {
        $publisher->publish($class, $user, 'pdf');
        $this->fail('Expected report cleanup to fail.');
    } catch (ReportArtifactCleanupException $exception) {
        expect($exception->getMessage())
            ->not->toContain('C:\\backend', 'storage.example.test', 'super-secret', "\r", "\n", 'FORGED LOG ENTRY')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->disk)->toBe('reports')
            ->and($exception->path)->toMatch('#^staging/.+\.pdf$#')
            ->and($exception->phase)->toBe('staging')
            ->and($exception->errorCode)->toBe('adapter_exception')
            ->and($exception->exceptionClass)->toBe(RuntimeException::class);
    }

    Log::shouldHaveReceived('error')->once()->with(
        Mockery::any(),
        Mockery::on(function (array $context): bool {
            $encoded = json_encode($context);

            return $context['disk'] === 'reports'
                && str_starts_with($context['path'], 'staging/')
                && $context['phase'] === 'staging'
                && $context['outcome'] === 'cleanup_failed'
                && $context['error_code'] === 'adapter_exception'
                && $context['exception_class'] === RuntimeException::class
                && ! str_contains($encoded, 'C:\\\\backend')
                && ! str_contains($encoded, 'storage.example.test')
                && ! str_contains($encoded, 'super-secret')
                && ! str_contains($encoded, 'FORGED LOG ENTRY')
                && ! str_contains($encoded, '\\r')
                && ! str_contains($encoded, '\\n');
        }),
    );
});
