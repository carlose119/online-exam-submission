<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

it('logs sending and transport acceptance without sensitive mail fields', function () {
    Log::spy();

    Mail::raw('private message body', function ($message): void {
        $message->to('recipient@example.test')->subject('Private subject');
    });

    foreach ([['mail.sending', 'attempt'], ['mail.sent', 'accepted']] as [$event, $outcome]) {
        Log::shouldHaveReceived('info')->with('Mail operation.', Mockery::on(
            fn (array $context): bool => $context['event'] === $event
                && $context['outcome'] === $outcome
                && $context['configured_mailer'] === 'array'
                && $context['recipient_count'] === 1
                && is_string($context['timestamp'])
                && array_keys($context) === ['event', 'outcome', 'configured_mailer', 'timestamp', 'recipient_count']
                && ! str_contains(json_encode($context), 'recipient@example.test')
                && ! str_contains(json_encode($context), 'Private subject')
                && ! str_contains(json_encode($context), 'private message body'),
        ))->once();
    }
});

it('rethrows a mail notification transport failure after safe failure logging', function () {
    Log::spy();
    $mailer = Mail::mailer('array');
    $mailer->setSymfonyTransport(new class implements TransportInterface
    {
        public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SentMessage
        {
            throw new TransportException('private transport detail');
        }

        public function __toString(): string
        {
            return 'failing-test://';
        }
    });

    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)
                ->subject('Private failure subject')
                ->line('private failure body');
        }
    };

    expect(fn () => Illuminate\Support\Facades\Notification::route('mail', 'private@example.test')->notify($notification))
        ->toThrow(TransportException::class, 'private transport detail');

    Log::shouldHaveReceived('warning')->with('Mail operation failed.', Mockery::on(
        fn (array $context): bool => $context['event'] === 'mail.notification_failed'
            && $context['outcome'] === 'failed'
            && $context['exception_class'] === TransportException::class
            && array_keys($context) === ['event', 'outcome', 'configured_mailer', 'timestamp', 'exception_class']
            && ! str_contains(json_encode($context), 'private'),
    ))->once();
});

it('logs safe queue metadata only for failed mail and notification jobs', function (string $jobClass, string $eventName) {
    Log::spy();
    $job = Mockery::mock(Job::class, function (MockInterface $mock) use ($jobClass): void {
        $mock->shouldReceive('resolveQueuedJobClass')->once()->andReturn($jobClass);
        $mock->shouldReceive('getQueue')->once()->andReturn('mail');
        $mock->shouldReceive('uuid')->once()->andReturn('safe-job-uuid');
    });

    Event::dispatch(new JobFailed('database', $job, new RuntimeException('private queue detail')));

    Log::shouldHaveReceived('warning')->with('Mail operation failed.', Mockery::on(
        fn (array $context): bool => $context['event'] === $eventName
            && $context['queue_connection'] === 'database'
            && $context['queue_name'] === 'mail'
            && $context['job_uuid'] === 'safe-job-uuid'
            && $context['exception_class'] === RuntimeException::class
            && array_keys($context) === ['event', 'outcome', 'configured_mailer', 'timestamp', 'exception_class', 'queue_connection', 'queue_name', 'job_uuid']
            && ! str_contains(json_encode($context), 'private queue detail'),
    ))->once();
})->with([
    'queued mailable' => [SendQueuedMailable::class, 'mail.job_failed'],
    'queued notification' => [SendQueuedNotifications::class, 'notification.job_failed'],
]);

it('uses only explicit delivery transports in failover configuration', function () {
    expect(config('mail.mailers.failover.mailers'))->toBe(['smtp'])
        ->and(config('mail.mailers.failover.mailers'))->not->toContain('log', 'array');
});

it('normalizes an explicit failover delivery list', function () {
    $_ENV['MAIL_FAILOVER_MAILERS'] = ' smtp_backup, smtp, smtp_backup ';

    try {
        $mailConfig = require config_path('mail.php');
    } finally {
        unset($_ENV['MAIL_FAILOVER_MAILERS']);
    }

    expect($mailConfig['mailers']['failover']['mailers'])->toBe(['smtp_backup', 'smtp']);
});

it('rejects invalid failover transport lists', function (string $mailers) {
    $_ENV['MAIL_FAILOVER_MAILERS'] = $mailers;

    try {
        require config_path('mail.php');
    } finally {
        unset($_ENV['MAIL_FAILOVER_MAILERS']);
    }
})->throws(InvalidArgumentException::class)->with([
    'log transport' => 'smtp,log',
    'empty list' => '',
    'unknown mailer' => 'smtp,unknown',
]);
