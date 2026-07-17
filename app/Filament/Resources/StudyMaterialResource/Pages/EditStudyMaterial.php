<?php

namespace App\Filament\Resources\StudyMaterialResource\Pages;

use App\Enums\StudyMaterialType;
use App\Filament\Resources\StudyMaterialResource;
use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditStudyMaterial extends EditRecord
{
    protected static string $resource = StudyMaterialResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->unpackFormData($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->packFormData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copyInvitationLink')
                ->label('Copy public join URL')
                ->icon('heroicon-o-clipboard')
                ->color('gray')
                ->action(function (): void {
                    $record = $this->getRecord();
                    $class = $record->classroom;

                    if (! $class instanceof SchoolClass) {
                        Notification::make()
                            ->title('No class associated')
                            ->danger()
                            ->send();

                        return;
                    }

                    $url = route('class.join.show', $class->invitation_code);

                    Notification::make()
                        ->title('Public join URL copied!')
                        ->body("URL: {$url}")
                        ->success()
                        ->persistent()
                        ->send();

                    $this->dispatch('clipboard-copy', url: $url);
                }),
        ];
    }

    /**
     * Merge virtual form fields into the persistence columns.
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
                'scheduled_at'   => $data['scheduled_at'] ?? null,
            ];
        }
        unset($data['meeting_title'], $data['scheduled_at']);

        return $data;
    }

    /**
     * Unpack JSON metadata and file columns into the virtual form fields.
     */
    private function unpackFormData(array $data): array
    {
        if (($data['type'] ?? null) === StudyMaterialType::File->value) {
            $data['uploaded_file'] = $data['file_path_or_url'] ?? null;
        }

        if (($data['type'] ?? null) === StudyMaterialType::Meeting->value && ! empty($data['extra_metadata'])) {
            $metadata = is_array($data['extra_metadata'])
                ? $data['extra_metadata']
                : json_decode($data['extra_metadata'], true);

            $data['meeting_title'] = $metadata['meeting_title'] ?? null;
            $data['scheduled_at']  = $metadata['scheduled_at'] ?? null;
        }

        return $data;
    }
}
