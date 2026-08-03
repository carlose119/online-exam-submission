<?php

namespace App\Filament\Resources\MeetingResource\Pages;

use App\Filament\Resources\MeetingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMeeting extends CreateRecord
{
    protected static string $resource = MeetingResource::class;

    /**
     * Transform the virtual recurrence fields into the recurrence_rule JSON
     * column value, and remove virtual fields before mass assignment.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $isRecurring = (bool) ($data['is_recurring'] ?? false);

        if ($isRecurring) {
            $data['recurrence_rule'] = json_encode([
                'frequency'     => $data['frequency'] ?? 'weekly',
                'interval'      => (int) ($data['interval'] ?? 1),
                'count'         => (int) ($data['count'] ?? 12),
                'until'         => null,
                'days_of_week'  => null,
            ]);
        } else {
            $data['recurrence_rule'] = null;
        }

        // Remove virtual form fields — they don't exist on the model.
        unset($data['is_recurring'], $data['frequency'], $data['interval'], $data['count']);

        return $data;
    }

    /**
     * After the parent meeting is created, eagerly materialize child instances
     * when the form was submitted with the recurring toggle enabled.
     */
    protected function afterCreate(): void
    {
        $isRecurring = (bool) ($this->data['is_recurring'] ?? false);
        $count       = (int)  ($this->data['count'] ?? 1);

        if ($isRecurring && $count > 1) {
            $this->record->generateInstances($count);
        }
    }
}
