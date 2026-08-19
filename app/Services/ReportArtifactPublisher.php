<?php

namespace App\Services;

use App\Exceptions\ReportArtifactCleanupException;
use App\Models\SchoolClass;
use App\Models\User;
use App\Values\ReportFilters;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ReportArtifactPublisher
{
    private const CLEANUP_FAILED = 'cleanup_failed';

    private const DELETE_FAILED = 'delete_failed';

    private const ADAPTER_EXCEPTION = 'adapter_exception';

    public function __construct(
        private readonly ClassReportService $reports,
        private readonly ReportFormatService $formats,
        private readonly ReportAccess $access,
    ) {}

    public function publish(SchoolClass $class, User $user, string $format, array $filters = ReportFilters::EMPTY): bool
    {
        $disk = Storage::disk(config('reports.storage_disk'));
        $extension = $format === 'pdf' ? 'pdf' : 'xlsx';
        $label = $format === 'pdf' ? 'PDF' : 'Excel';
        $identity = (string) Str::uuid();
        $staged = "staging/{$identity}.{$extension}";
        $filename = null;
        $published = false;
        $moved = false;

        try {
            $data = $this->reports->generate($class, $filters);
            $format === 'pdf'
                ? $this->formats->toPdf($data, $class, $staged)
                : $this->formats->toExcel($data, $class, $staged);

            DB::transaction(function () use ($class, $user, $label, $extension, $identity, $disk, $staged, &$filename, &$published, &$moved): void {
                $lockedUser = User::query()->lockForUpdate()->find($user->id);
                $lockedClass = SchoolClass::query()->lockForUpdate()->find($class->id);

                if (! $lockedUser || ! $lockedClass || ! $this->access->allows($lockedUser, $lockedClass)) {
                    return;
                }

                $filename = $this->formats->filename($lockedClass, $extension, $identity);

                if (! $disk->move($staged, $filename)) {
                    throw new \RuntimeException('Unable to publish report artifact.');
                }
                $moved = true;

                Notification::make()
                    ->title($label.' Report Ready')
                    ->body("The {$label} report for \"{$lockedClass->title}\" has been generated.")
                    ->success()
                    ->actions([
                        Action::make('download')
                            ->label('Download '.$label)
                            ->url(route('reports.download', ['filename' => $filename])),
                    ])
                    ->sendToDatabase($lockedUser);

                $published = true;
            });
        } catch (Throwable $exception) {
            if ($moved && $filename !== null) {
                $this->cleanup($disk, $filename, 'canonical');
            }

            throw $exception;
        } finally {
            if (! $moved) {
                $this->cleanup($disk, $staged, 'staging');
            }
        }

        return $published;
    }

    private function cleanup(mixed $disk, string $path, string $phase): void
    {
        $errorCode = self::DELETE_FAILED;
        $exceptionClass = null;

        try {
            if (! $disk->exists($path) || $disk->delete($path)) {
                return;
            }
        } catch (Throwable $exception) {
            $errorCode = self::ADAPTER_EXCEPTION;
            $class = $exception::class;
            $exceptionClass = strlen($class) <= 200 && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $class) === 1
                ? $class
                : null;
        }

        $failure = new ReportArtifactCleanupException(
            (string) config('reports.storage_disk'),
            $path,
            $phase,
            $errorCode,
            $exceptionClass,
        );
        Log::error($failure->getMessage(), [
            'disk' => $failure->disk,
            'path' => $failure->path,
            'phase' => $failure->phase,
            'outcome' => self::CLEANUP_FAILED,
            'error_code' => $failure->errorCode,
            'exception_class' => $failure->exceptionClass,
        ]);

        throw $failure;
    }
}
