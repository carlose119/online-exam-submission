<?php

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;
use Throwable;

class MailOperationsSubscriber
{
    public function handleSending(MessageSending $event): void
    {
        Log::info('Mail operation.', $this->mailMetadata('mail.sending', 'attempt', $event->message));
    }

    public function handleSent(MessageSent $event): void
    {
        Log::info('Mail operation.', $this->mailMetadata('mail.sent', 'accepted', $event->message));
    }

    public function handleNotificationFailed(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $exception = $event->data['exception'] ?? null;

        Log::warning('Mail operation failed.', $this->failureMetadata(
            'mail.notification_failed',
            $exception instanceof Throwable ? $exception : null,
        ));
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $jobClass = $event->job->resolveQueuedJobClass();

        if (! in_array($jobClass, [
            SendQueuedMailable::class,
            SendQueuedNotifications::class,
        ], true)) {
            return;
        }

        Log::warning('Mail operation failed.', array_merge(
            $this->failureMetadata(
                $jobClass === SendQueuedMailable::class ? 'mail.job_failed' : 'notification.job_failed',
                $event->exception,
            ),
            [
                'queue_connection' => $event->connectionName,
                'queue_name' => $event->job->getQueue(),
                'job_uuid' => $event->job->uuid(),
            ],
        ));
    }

    /** @return array<string, int|string> */
    private function mailMetadata(string $event, string $outcome, Email $message): array
    {
        return array_merge($this->metadata($event, $outcome), [
            'recipient_count' => count($message->getTo()) + count($message->getCc()) + count($message->getBcc()),
        ]);
    }

    /** @return array<string, string> */
    private function failureMetadata(string $event, ?Throwable $exception): array
    {
        return array_merge(
            $this->metadata($event, 'failed'),
            $exception !== null ? ['exception_class' => $exception::class] : [],
        );
    }

    /** @return array<string, string> */
    private function metadata(string $event, string $outcome): array
    {
        return [
            'event' => $event,
            'outcome' => $outcome,
            'configured_mailer' => (string) config('mail.default'),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            MessageSending::class => 'handleSending',
            MessageSent::class => 'handleSent',
            NotificationFailed::class => 'handleNotificationFailed',
            JobFailed::class => 'handleJobFailed',
        ];
    }
}
