<?php

use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\IcalBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

it('persists unique 64-character feed tokens and overwrites them on regeneration', function () {
    expect(Schema::hasColumn('users', 'feed_token'))->toBeTrue()
        ->and(Schema::getColumnType('users', 'feed_token'))->toBe('varchar')
        ->and(Schema::hasIndex('users', ['feed_token'], 'unique'))->toBeTrue();

    $user = User::factory()->create();
    $user->fill(['feed_token' => str_repeat('x', 64)]);

    expect($user->feed_token)->toBeNull();

    $firstToken = $user->generateFeedToken();

    expect($firstToken)->toHaveLength(64)
        ->and($user->fresh()->feed_token)->toBe($firstToken)
        ->and($user->toArray())->not->toHaveKey('feed_token');

    $secondToken = $user->regenerateFeedToken();

    expect($secondToken)->toHaveLength(64)
        ->and($secondToken)->not->toBe($firstToken)
        ->and($user->fresh()->feed_token)->toBe($secondToken);

    $otherUser = User::factory()->create();
    $otherUser->feed_token = $secondToken;

    expect(fn () => $otherUser->save())->toThrow(QueryException::class);
});

it('returns 404 for an unknown feed token', function () {
    $this->get(route('calendar.feed', ['token' => 'missing']))->assertNotFound();
});

it('returns an unauthenticated multi-event feed with exact no-cache headers', function () {
    Carbon::setTestNow('2026-08-05 12:00:00');

    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create(['title' => 'Calendar Class', 'teacher_id' => $teacher->id, 'invitation_code' => 'CALENDAR']);
    $past = Meeting::create(['class_id' => $class->id, 'title' => 'Past Meeting', 'scheduled_at' => now()->subDay()]);
    $future = Meeting::create(['class_id' => $class->id, 'title' => 'Future Meeting', 'scheduled_at' => now()->addDay()]);
    $student = User::factory()->create(['role' => 'STUDENT']);
    $student->subscribedClasses()->attach($class->id);

    $response = $this->get(route('calendar.feed', ['token' => $student->generateFeedToken()]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('Content-Disposition', 'inline; filename="calendar.ics"')
        ->assertHeader('Cache-Control', 'no-store, max-age=0')
        ->assertHeader('Pragma', 'no-cache')
        ->assertSee($past->title)
        ->assertSee($future->title);

    expect(substr_count($response->getContent(), 'BEGIN:VCALENDAR'))->toBe(1)
        ->and(substr_count($response->getContent(), 'BEGIN:VEVENT'))->toBe(2)
        ->and($response->getContent())->toContain("\r\n");

    Carbon::setTestNow();
});

it('includes only subscribed meetings and materialized recurring instances', function () {
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $subscribedClass = SchoolClass::create(['title' => 'Subscribed', 'teacher_id' => $teacher->id, 'invitation_code' => 'SUBSCRIB']);
    $unsubscribedClass = SchoolClass::create(['title' => 'Unsubscribed', 'teacher_id' => $teacher->id, 'invitation_code' => 'UNSUBSCR']);
    $parent = Meeting::create([
        'class_id' => $subscribedClass->id,
        'title' => 'Recurring Meeting',
        'scheduled_at' => now()->addDay(),
        'recurrence_rule' => json_encode(['frequency' => 'weekly', 'interval' => 1]),
    ]);
    $parent->generateInstances(4);
    Meeting::create(['class_id' => $unsubscribedClass->id, 'title' => 'Private Meeting', 'scheduled_at' => now()->addDay()]);
    $student = User::factory()->create(['role' => 'STUDENT']);
    $student->subscribedClasses()->attach($subscribedClass->id);

    $response = $this->get(route('calendar.feed', ['token' => $student->generateFeedToken()]));

    $response->assertOk()->assertSee('Recurring Meeting')->assertDontSee('Private Meeting')->assertDontSee('RRULE');
    expect(substr_count($response->getContent(), 'BEGIN:VEVENT'))->toBe(4);

    $meeting = $parent->fresh()->load('classroom.teacher');
    expect(app(IcalBuilder::class)->buildMany([$meeting]))->toBe(app(IcalBuilder::class)->build($meeting));
});
