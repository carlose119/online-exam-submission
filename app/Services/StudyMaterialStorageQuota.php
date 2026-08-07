<?php

namespace App\Services;

use App\Enums\StudyMaterialType;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StudyMaterialStorageQuota
{
    /** @return array{used: int, limit: int, remaining: int} */
    public function usage(int $teacherId): array
    {
        $paths = StudyMaterial::query()
            ->where('type', StudyMaterialType::File->value)
            ->whereNotNull('file_path_or_url')
            ->whereHas('classroom', fn ($query) => $query->where('teacher_id', $teacherId))
            ->distinct()
            ->pluck('file_path_or_url');

        $disk = Storage::disk(config('study-materials.disk'));
        $used = $paths->sum(function (string $path) use ($disk): int {
            try {
                return $disk->exists($path) ? $disk->size($path) : 0;
            } catch (Throwable) {
                return 0;
            }
        });
        $limit = (int) config('study-materials.teacher_quota_bytes');

        return ['used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used)];
    }

    public function violation(
        int $teacherId,
        int $classId,
        int $uploadBytes,
        ?StudyMaterial $replaced = null,
    ): ?string {
        $class = SchoolClass::findOrFail($classId);

        if ((int) $class->teacher_id !== $teacherId) {
            throw new AuthorizationException('You do not own the selected class.');
        }

        $usage = $this->usage($teacherId);
        $credit = $this->replacementCredit($replaced);

        if (($usage['used'] + $uploadBytes - $credit) <= $usage['limit']) {
            return null;
        }

        return sprintf(
            'Storage quota exceeded. Used: %s; limit: %s; remaining: %s. Upload a smaller file or ask an administrator to increase the quota.',
            $this->formatBytes($usage['used']),
            $this->formatBytes($usage['limit']),
            $this->formatBytes($usage['remaining']),
        );
    }

    public function summary(int $teacherId): string
    {
        $usage = $this->usage($teacherId);

        return sprintf(
            'Storage used: %s of %s. Remaining: %s.',
            $this->formatBytes($usage['used']),
            $this->formatBytes($usage['limit']),
            $this->formatBytes($usage['remaining']),
        );
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes;

        foreach ($units as $unit) {
            $value /= 1024;

            if ($value < 1024 || $unit === 'TB') {
                return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.')." {$unit}";
            }
        }

        return "{$bytes} B";
    }

    private function replacementCredit(?StudyMaterial $material): int
    {
        $path = $material?->type === StudyMaterialType::File ? $material->file_path_or_url : null;

        if (! is_string($path) || blank($path) || StudyMaterial::query()
            ->where('type', StudyMaterialType::File->value)
            ->where('file_path_or_url', $path)
            ->count() !== 1) {
            return 0;
        }

        try {
            $disk = Storage::disk(config('study-materials.disk'));

            return $disk->exists($path) ? $disk->size($path) : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
