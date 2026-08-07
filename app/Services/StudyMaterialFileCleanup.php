<?php

namespace App\Services;

use App\Enums\StudyMaterialType;
use App\Models\StudyMaterial;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StudyMaterialFileCleanup
{
    public const DELETED = 'deleted';

    public const STILL_REFERENCED = 'still-referenced';

    public const REJECTED = 'unmanaged-or-rejected';

    public const MISSING = 'missing';

    public const FAILED = 'failed';

    public function cleanup(mixed $candidate): string
    {
        $path = $this->managedPath($candidate);

        if ($path === null) {
            return self::REJECTED;
        }

        if (StudyMaterial::query()
            ->where('type', StudyMaterialType::File->value)
            ->where('file_path_or_url', $path)
            ->exists()) {
            return self::STILL_REFERENCED;
        }

        try {
            $disk = Storage::disk(config('study-materials.disk'));

            if (! $disk->exists($path)) {
                return self::MISSING;
            }

            if ($disk->delete($path)) {
                return self::DELETED;
            }
        } catch (Throwable $exception) {
            $this->warn($path, $exception::class);

            return self::FAILED;
        }

        $this->warn($path);

        return self::FAILED;
    }

    private function managedPath(mixed $candidate): ?string
    {
        if (! is_string($candidate) || blank($candidate) || str_contains($candidate, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        $configuredPrefix = (string) config('study-materials.path_prefix');
        $prefix = trim($configuredPrefix, '/');

        if ($prefix === '' || str_starts_with($configuredPrefix, '/') || str_contains($configuredPrefix, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $configuredPrefix) === 1
            || str_starts_with($candidate, '/') || preg_match('/^[A-Za-z]:/', $candidate) === 1) {
            return null;
        }

        $segments = explode('/', $candidate);
        $prefixSegments = explode('/', $prefix);

        if (array_intersect(['', '.', '..'], $segments) !== []
            || array_intersect(['', '.', '..'], $prefixSegments) !== []) {
            return null;
        }

        return str_starts_with($candidate, $prefix.'/') ? implode('/', $segments) : null;
    }

    private function warn(string $path, ?string $exception = null): void
    {
        Log::warning('Study material file cleanup failed.', array_filter([
            'disk' => config('study-materials.disk'),
            'path_hash' => hash('sha256', $path),
            'outcome' => self::FAILED,
            'exception' => $exception,
        ]));
    }
}
