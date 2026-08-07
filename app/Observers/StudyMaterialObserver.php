<?php

namespace App\Observers;

use App\Enums\StudyMaterialType;
use App\Models\StudyMaterial;
use App\Services\StudyMaterialFileCleanup;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class StudyMaterialObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly StudyMaterialFileCleanup $cleanup) {}

    public function updated(StudyMaterial $material): void
    {
        $previous = $material->getPrevious();
        $oldType = $previous['type'] ?? $material->type->value;
        $oldPath = array_key_exists('file_path_or_url', $previous)
            ? $previous['file_path_or_url']
            : $material->file_path_or_url;

        if ($oldType !== StudyMaterialType::File->value) {
            return;
        }

        $referenceRemainsActive = $material->type === StudyMaterialType::File
            && $material->file_path_or_url === $oldPath;

        if (! $referenceRemainsActive) {
            $this->cleanup->cleanup($oldPath);
        }
    }

    public function deleted(StudyMaterial $material): void
    {
        if ($material->type === StudyMaterialType::File) {
            $this->cleanup->cleanup($material->file_path_or_url);
        }
    }
}
