<?php

namespace App\Filament\Resources\StudyMaterialResource\Pages;

use App\Enums\StudyMaterialType;
use App\Filament\Resources\StudyMaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStudyMaterial extends CreateRecord
{
    protected static string $resource = StudyMaterialResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->packFormData($data);
    }

    /**
     * Merge virtual form fields into the persistence columns.
     *
     * - FILE type: moves `uploaded_file` → `file_path_or_url`.
     * - MEETING type: packs `meeting_title` and `scheduled_at` → `extra_metadata` JSON.
     */
    private function packFormData(array $data): array
    {
        if (($data['type'] ?? null) === StudyMaterialType::File->value) {
            if (! empty($data['uploaded_file'])) {
                $data['file_path_or_url'] = $data['uploaded_file'];
            }
        }
        unset($data['uploaded_file']);

        if (($data['type'] ?? null) === StudyMaterialType::Meeting->value) {
            $data['extra_metadata'] = [
                'meeting_title' => $data['meeting_title'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
            ];
        }
        unset($data['meeting_title'], $data['scheduled_at']);

        return $data;
    }
}
