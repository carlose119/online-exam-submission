<?php

namespace App\Filament\Resources\MeetingResource\Pages;

use App\Filament\Resources\MeetingResource;
use Filament\Resources\Pages\EditRecord;

class EditMeeting extends EditRecord
{
    protected static string $resource = MeetingResource::class;

    /**
     * When editing a recurring parent, propagate shared-field changes to all
     * **future** child instances. Past children are left untouched.
     */
    protected function afterSave(): void
    {
        if (! $this->record->isRecurring()) {
            return;
        }

        $this->record->children()
            ->where('scheduled_at', '>=', now())
            ->update([
                'title'            => $this->record->title,
                'agenda'           => $this->record->agenda,
                'duration_minutes' => $this->record->duration_minutes,
                'meeting_url'      => $this->record->meeting_url,
            ]);
    }
}
