<?php

namespace App\Filament\Resources\MeetingResource\Pages;

use App\Filament\Resources\MeetingResource;
use App\Models\Meeting;
use App\Services\MeetingScheduledNotificationDispatcher;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateMeeting extends CreateRecord
{
    protected static string $resource = MeetingResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /**
     * Transform the virtual recurrence fields into the recurrence_rule JSON
     * column value, and remove virtual fields before mass assignment.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $isRecurring = (bool) ($data['is_recurring'] ?? false);

        if ($isRecurring) {
            $data['recurrence_rule'] = json_encode([
                'frequency' => $data['frequency'] ?? 'weekly',
                'interval' => (int) ($data['interval'] ?? 1),
                'count' => (int) ($data['count'] ?? 12),
                'until' => null,
                'days_of_week' => in_array($data['frequency'] ?? 'weekly', ['weekly', 'biweekly'], true)
                    ? ($data['days_of_week'] ?? null) : null,
            ]);
        } else {
            $data['recurrence_rule'] = null;
        }

        if ($isRecurring) {
            $first = Meeting::occurrenceDates(Carbon::parse($data['scheduled_at']), json_decode($data['recurrence_rule'], true), 1)[0];
            $data['scheduled_at'] = $first->format('Y-m-d H:i:s');
        }

        // Remove virtual form fields — they don't exist on the model.
        unset($data['is_recurring'], $data['frequency'], $data['interval'], $data['count'], $data['days_of_week']);

        return $data;
    }

    /**
     * After the parent meeting is created, eagerly materialize child instances
     * when the form was submitted with the recurring toggle enabled.
     */
    protected function afterCreate(): void
    {
        $isRecurring = (bool) ($this->data['is_recurring'] ?? false);
        $count = (int) ($this->data['count'] ?? 1);

        if ($isRecurring && $count > 1) {
            $this->record->generateInstances($count);
        }

        app(MeetingScheduledNotificationDispatcher::class)->dispatch($this->record, $isRecurring ? $count : 1);
    }
}
