<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingScheduledNotification;
use Illuminate\Support\Facades\DB;

class MeetingScheduledNotificationDispatcher
{
    public function dispatch(Meeting $meeting, int $occurrenceCount): void
    {
        $meeting->loadMissing('classroom:id,title');

        // Freeze eligibility at the successful creation snapshot; rollback drops this callback.
        $recipientIds = $meeting->classroom->students()
            ->where('users.role', 'STUDENT')
            ->whereNotNull('users.email_verified_at')
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $notificationData = [
            $meeting->title,
            $meeting->classroom->title,
            $meeting->scheduled_at->toIso8601String(),
            $meeting->duration_minutes ?? 60,
            $occurrenceCount,
            $meeting->isRecurring(),
        ];

        DB::afterCommit(function () use ($recipientIds, $notificationData): void {
            User::query()->whereKey($recipientIds)->get()->each(
                fn (User $recipient) => $recipient->notify(new MeetingScheduledNotification(...$notificationData)),
            );
        });
    }
}
