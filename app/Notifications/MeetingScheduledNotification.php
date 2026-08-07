<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Carbon;

#[Tries(3)]
class MeetingScheduledNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $meetingTitle,
        public readonly string $classTitle,
        public readonly string $scheduledAt,
        public readonly int $durationMinutes,
        public readonly int $occurrenceCount,
        public readonly bool $isSeries,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $schedule = Carbon::parse($this->scheduledAt)->format('M j, Y \a\t g:i A');
        $message = (new MailMessage)
            ->subject($this->isSeries ? 'Meeting series scheduled' : 'Meeting scheduled')
            ->line("{$this->meetingTitle} has been scheduled for {$this->classTitle}.")
            ->line("First meeting: {$schedule} ({$this->durationMinutes} minutes).")
            ->action('View class schedule', route('dashboard'));

        if ($this->isSeries) {
            $message->line("This series contains {$this->occurrenceCount} meetings.");
        }

        return $message;
    }
}
