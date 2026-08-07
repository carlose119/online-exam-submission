<?php

namespace App\Services;

use App\Enums\StudyMaterialType;
use App\Models\StudyMaterial;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StudyMaterialFileReconciliation
{
    public function __construct(private readonly StudyMaterialFileCleanup $cleanup) {}

    /** @return array{scanned: int, active: int, orphaned: int, deleted: int, skipped: int, failed: int} */
    public function reconcile(bool $delete = false): array
    {
        $counts = array_fill_keys(['scanned', 'active', 'orphaned', 'deleted', 'skipped', 'failed'], 0);
        $prefix = $this->managedPrefix();

        if ($prefix === null) {
            $counts['failed'] = 1;
            $this->warn('invalid-prefix');

            return $counts;
        }

        try {
            $active = StudyMaterial::query()
                ->where('type', StudyMaterialType::File->value)
                ->whereNotNull('file_path_or_url')
                ->pluck('file_path_or_url')
                ->filter(fn (mixed $path): bool => is_string($path))
                ->flip();
            $files = Storage::disk(config('study-materials.disk'))->allFiles($prefix);
        } catch (Throwable $exception) {
            $counts['failed'] = 1;
            $this->warn('scan-failed', $exception::class);

            return $counts;
        }

        foreach ($files as $path) {
            if (! is_string($path) || ! str_starts_with($path, $prefix.'/')) {
                continue;
            }

            $counts['scanned']++;

            if ($active->has($path)) {
                $counts['active']++;

                continue;
            }

            $counts['orphaned']++;

            if (! $delete) {
                $counts['skipped']++;

                continue;
            }

            try {
                $outcome = $this->cleanup->cleanup($path);
            } catch (Throwable $exception) {
                $counts['failed']++;
                $this->warn('cleanup-failed', $exception::class, $path);

                continue;
            }

            match ($outcome) {
                StudyMaterialFileCleanup::DELETED => $counts['deleted']++,
                StudyMaterialFileCleanup::FAILED => $counts['failed']++,
                default => $counts['skipped']++,
            };
        }

        return $counts;
    }

    private function managedPrefix(): ?string
    {
        $configured = (string) config('study-materials.path_prefix');
        $prefix = trim($configured, '/');
        $segments = explode('/', $prefix);

        return $prefix !== '' && $configured === $prefix && ! str_contains($configured, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $configured) !== 1
            && array_intersect(['', '.', '..'], $segments) === [] ? $prefix : null;
    }

    private function warn(string $outcome, ?string $exception = null, ?string $path = null): void
    {
        Log::warning('Study material file reconciliation failed.', array_filter([
            'disk' => config('study-materials.disk'),
            'path_hash' => $path === null ? null : hash('sha256', $path),
            'outcome' => $outcome,
            'exception' => $exception,
        ]));
    }
}
