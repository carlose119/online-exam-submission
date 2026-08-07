<?php

use App\Filament\Resources\MeetingResource\Pages\CreateMeeting;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\MeetingScheduledNotification;
use App\Services\MeetingScheduledNotificationDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

function notificationClassroom(): array
{
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $classroom = SchoolClass::create([
        'title' => 'Secure Systems',
        'teacher_id' => $teacher->id,
        'invitation_code' => fake()->unique()->bothify('NOTIF###'),
    ]);

    return [$teacher, $classroom];
}

function createMeeting(SchoolClass $classroom, array $overrides = []): void
{
    Livewire::test(CreateMeeting::class)
        ->fillForm(array_merge([
            'class_id' => $classroom->id,
            'title' => 'Transaction boundaries',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 45,
            'is_recurring' => false,
        ], $overrides))
        ->call('create')
        ->assertHasNoFormErrors();
}

it('queues one committed notification only for each current verified student member', function () {
    Queue::fake();
    [$teacher, $classroom] = notificationClassroom();
    $eligible = User::factory()->create(['role' => 'STUDENT']);
    $unverified = User::factory()->unverified()->create(['role' => 'STUDENT']);
    $nonStudent = User::factory()->create(['role' => 'TEACHER']);
    User::factory()->create(['role' => 'STUDENT']);
    $classroom->students()->attach([$eligible->id, $unverified->id, $nonStudent->id]);
    Auth::login($teacher);

    createMeeting($classroom);

    Queue::assertPushed(SendQueuedNotifications::class, 1);
    Queue::assertPushed(SendQueuedNotifications::class, function ($job) use ($eligible): bool {
        return $job->afterCommit === true
            && $job->notifiables->count() === 1
            && $job->notifiables->first()->is($eligible)
            && $job->notification instanceof MeetingScheduledNotification
            && ! $job->notification->isSeries;
    });

    [$emptyTeacher, $emptyClassroom] = notificationClassroom();
    Auth::login($emptyTeacher);
    createMeeting($emptyClassroom);
    Queue::assertPushed(SendQueuedNotifications::class, 1);
});

it('queues one isolated series summary per recipient after all children are materialized', function () {
    Queue::fake();
    [$teacher, $classroom] = notificationClassroom();
    $students = User::factory()->count(2)->create(['role' => 'STUDENT']);
    $classroom->students()->attach($students);
    Auth::login($teacher);

    createMeeting($classroom, [
        'is_recurring' => true,
        'frequency' => 'weekly',
        'interval' => 1,
        'count' => 4,
    ]);

    expect(Meeting::count())->toBe(4);
    Queue::assertPushed(SendQueuedNotifications::class, 2);
    expect(Queue::pushed(SendQueuedNotifications::class)
        ->map(fn ($job) => $job->notifiables->sole()->id)->sort()->values()->all())
        ->toBe($students->pluck('id')->sort()->values()->all());
    Queue::assertPushed(SendQueuedNotifications::class, function ($job): bool {
        expect($job->notifiables)->toHaveCount(1)
            ->and($job->notification->isSeries)->toBeTrue()
            ->and($job->notification->occurrenceCount)->toBe(4);

        return true;
    });
});

it('does not queue when the creation transaction rolls back', function () {
    Queue::fake();
    [, $classroom] = notificationClassroom();
    $student = User::factory()->create(['role' => 'STUDENT']);
    $classroom->students()->attach($student);
    $meeting = Meeting::create([
        'class_id' => $classroom->id,
        'title' => 'Rollback meeting',
        'scheduled_at' => now()->addDay(),
    ]);

    DB::beginTransaction();
    app(MeetingScheduledNotificationDispatcher::class)->dispatch($meeting, 1);
    DB::rollBack();

    Queue::assertNothingPushed();
});

it('rolls back the real recurring create action after callback registration fails', function () {
    Queue::fake();
    [$teacher, $classroom] = notificationClassroom();
    $student = User::factory()->create(['role' => 'STUDENT']);
    $classroom->students()->attach($student);
    Auth::login($teacher);
    app()->bind(MeetingScheduledNotificationDispatcher::class, fn () => new class extends MeetingScheduledNotificationDispatcher
    {
        public function dispatch(Meeting $meeting, int $occurrenceCount): void
        {
            parent::dispatch($meeting, $occurrenceCount);

            throw new RuntimeException('Fail after meeting notification registration.');
        }
    });

    expect(fn () => createMeeting($classroom, [
        'is_recurring' => true,
        'frequency' => 'weekly',
        'interval' => 1,
        'count' => 4,
    ]))->toThrow(RuntimeException::class, 'Fail after meeting notification registration.');

    expect(Meeting::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('renders only safe schedule context and a dashboard action', function () {
    Queue::fake();
    [, $classroom] = notificationClassroom();
    $student = User::factory()->create([
        'role' => 'STUDENT',
        'feed_token' => 'private-feed-token',
        'password' => 'private-password',
    ]);
    $classroom->students()->attach($student);
    $meeting = Meeting::create([
        'class_id' => $classroom->id,
        'title' => 'Safe meeting title',
        'scheduled_at' => '2026-08-10 14:00:00',
        'duration_minutes' => 30,
        'meeting_url' => 'https://private-meeting.example.test',
        'agenda' => 'private agenda and answers',
    ]);

    DB::transaction(fn () => app(MeetingScheduledNotificationDispatcher::class)->dispatch($meeting, 1));

    $job = Queue::pushed(SendQueuedNotifications::class)->first();
    $mail = $job->notification->toMail($student);
    $html = $mail->render()->toHtml();
    $payload = serialize($job);

    expect($html)->toContain('Safe meeting title', 'Secure Systems', 'Aug 10, 2026', route('dashboard'))
        ->and($html)->not->toContain('private agenda', 'private-meeting.example.test', 'private-feed-token', 'private-password')
        ->and($payload)->not->toContain('private agenda', 'private-meeting.example.test', 'private-feed-token', 'private-password', 'answers');
});

it('propagates transport failures through metadata-only mail observability', function () {
    Log::spy();
    Mail::mailer('array')->setSymfonyTransport(new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('private meeting transport detail');
        }

        public function __toString(): string
        {
            return 'failing-meeting-test://';
        }
    });
    $notification = new MeetingScheduledNotification(
        'Safe title', 'Safe class', now()->toIso8601String(), 30, 1, false,
    );

    expect(fn () => NotificationFacade::route('mail', 'private@example.test')->notifyNow($notification))
        ->toThrow(TransportException::class, 'private meeting transport detail');

    Log::shouldHaveReceived('warning')->with('Mail operation failed.', Mockery::on(
        fn (array $context): bool => $context['event'] === 'mail.notification_failed'
            && $context['exception_class'] === TransportException::class
            && ! str_contains(json_encode($context), 'private'),
    ))->once();
});
