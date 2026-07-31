<?php

use App\Filament\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

// ---------------------------------------------------------------------------
// (a) Teacher query scope shows only their own meetings
// ---------------------------------------------------------------------------

it('teacher query scope shows only their own meetings', function () {
    $teacher = User::create([
        'name' => 'Meeting Scope Teacher',
        'email' => 'meet-scope@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $otherTeacher = User::create([
        'name' => 'Other Meeting Teacher',
        'email' => 'other-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'My Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'MEETSCO1',
    ]);

    $otherClass = SchoolClass::create([
        'title' => 'Other Class',
        'teacher_id' => $otherTeacher->id,
        'invitation_code' => 'MEETOTH1',
    ]);

    $myMeeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'My Meeting',
        'scheduled_at' => now()->addHour(),
    ]);

    $otherMeeting = Meeting::create([
        'class_id' => $otherClass->id,
        'title' => 'Other Meeting',
        'scheduled_at' => now()->addHour(),
    ]);

    Auth::login($teacher);
    $results = MeetingResource::getEloquentQuery()->get();

    expect($results->pluck('id'))->toContain($myMeeting->id);
    expect($results->pluck('id'))->not->toContain($otherMeeting->id);
    expect($results)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// (b) Admin sees all meetings
// ---------------------------------------------------------------------------

it('admin sees all meetings', function () {
    $admin = User::create([
        'name' => 'Admin Meeting',
        'email' => 'admin-meet@test.com',
        'password' => 'password',
        'role' => 'ADMIN',
    ]);

    $teacherA = User::create([
        'name' => 'Teacher A',
        'email' => 'meet-ta@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $teacherB = User::create([
        'name' => 'Teacher B',
        'email' => 'meet-tb@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $classA = SchoolClass::create([
        'title' => 'Class A',
        'teacher_id' => $teacherA->id,
        'invitation_code' => 'MEETADM1',
    ]);

    $classB = SchoolClass::create([
        'title' => 'Class B',
        'teacher_id' => $teacherB->id,
        'invitation_code' => 'MEETADM2',
    ]);

    Meeting::create(['class_id' => $classA->id, 'title' => 'Meeting A', 'scheduled_at' => now()->addHour()]);
    Meeting::create(['class_id' => $classB->id, 'title' => 'Meeting B', 'scheduled_at' => now()->addHour()]);

    Auth::login($admin);
    $results = MeetingResource::getEloquentQuery()->get();

    expect($results)->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// (c) Cross-teacher access returns empty
// ---------------------------------------------------------------------------

it('cross-teacher access returns empty query', function () {
    $teacherA = User::create([
        'name' => 'Cross Meet A',
        'email' => 'cross-meetA@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $teacherB = User::create([
        'name' => 'Cross Meet B',
        'email' => 'cross-meetB@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $classB = SchoolClass::create([
        'title' => 'Teacher B Class',
        'teacher_id' => $teacherB->id,
        'invitation_code' => 'MEETCRS1',
    ]);

    Meeting::create(['class_id' => $classB->id, 'title' => 'B Meeting', 'scheduled_at' => now()->addHour()]);

    Auth::login($teacherA);
    $results = MeetingResource::getEloquentQuery()->get();

    expect($results)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// (d) Meeting with all required fields persists correctly
// ---------------------------------------------------------------------------

it('meeting with all required fields persists correctly', function () {
    $teacher = User::create([
        'name' => 'Persist Teacher',
        'email' => 'persist-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Persist Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PERSIST1',
    ]);

    $scheduled = now()->addDays(2);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Test Meeting',
        'scheduled_at' => $scheduled,
        'duration_minutes' => 90,
        'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        'agenda' => 'Discuss project updates',
    ]);

    expect($meeting->id)->not->toBeNull();
    expect($meeting->title)->toBe('Test Meeting');
    expect($meeting->duration_minutes)->toBe(90);
    expect($meeting->meeting_url)->toBe('https://meet.google.com/abc-defg-hij');
    expect($meeting->agenda)->toBe('Discuss project updates');
    expect($meeting->scheduled_at->timestamp)->toEqual($scheduled->timestamp);
});

// ---------------------------------------------------------------------------
// (e) Fillable mass-assign works for all 6 fields
// ---------------------------------------------------------------------------

it('fillable mass-assigns all fields', function () {
    $teacher = User::create([
        'name' => 'Fillable Teacher',
        'email' => 'fillable-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Fillable Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'FILLMEE1',
    ]);

    $now = now()->addHour();

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'All Fields',
        'scheduled_at' => $now,
        'duration_minutes' => 45,
        'meeting_url' => 'https://zoom.us/j/12345',
        'agenda' => 'Agenda text',
    ]);

    expect(Meeting::find($meeting->id))->not->toBeNull();
    expect($meeting->fresh()->title)->toBe('All Fields');
    expect($meeting->fresh()->duration_minutes)->toBe(45);
});

// ---------------------------------------------------------------------------
// (f) classroom() relationship resolves
// ---------------------------------------------------------------------------

it('classroom relationship resolves', function () {
    $teacher = User::create([
        'name' => 'Rel Teacher',
        'email' => 'rel-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Math 101',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'RELMEET1',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Math Session',
        'scheduled_at' => now()->addHour(),
    ]);

    expect($meeting->classroom)->not->toBeNull();
    expect($meeting->classroom->title)->toBe('Math 101');
});

// ---------------------------------------------------------------------------
// (g) Cascade delete: deleting a class removes its meetings
// ---------------------------------------------------------------------------

it('cascade delete removes meetings when class is deleted', function () {
    $teacher = User::create([
        'name' => 'Cascade Meet Teacher',
        'email' => 'cascade-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Cascade Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'CSCMEET1',
    ]);

    $m1 = Meeting::create(['class_id' => $class->id, 'title' => 'M1', 'scheduled_at' => now()->addHour()]);
    $m2 = Meeting::create(['class_id' => $class->id, 'title' => 'M2', 'scheduled_at' => now()->addHour()]);
    $m3 = Meeting::create(['class_id' => $class->id, 'title' => 'M3', 'scheduled_at' => now()->addHour()]);

    $class->delete();

    expect(Meeting::find($m1->id))->toBeNull();
    expect(Meeting::find($m2->id))->toBeNull();
    expect(Meeting::find($m3->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// (h) N meetings per class allowed (no unique constraint)
// ---------------------------------------------------------------------------

it('n meetings per class allowed', function () {
    $teacher = User::create([
        'name' => 'N Meet Teacher',
        'email' => 'n-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Multi Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'NMULTI01',
    ]);

    Meeting::create(['class_id' => $class->id, 'title' => 'M1', 'scheduled_at' => now()->addHour()]);
    Meeting::create(['class_id' => $class->id, 'title' => 'M2', 'scheduled_at' => now()->addHours(2)]);

    expect(Meeting::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// (i) upcoming scope returns meetings >= now
// ---------------------------------------------------------------------------

it('upcoming scope returns meetings scheduled at or after now', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'Upcoming Teacher',
        'email' => 'upcoming-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Upcoming Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'UPCOMING',
    ]);

    $future = Meeting::create(['class_id' => $class->id, 'title' => 'Future', 'scheduled_at' => now()->addHour()]);
    $nowMeeting = Meeting::create(['class_id' => $class->id, 'title' => 'Now', 'scheduled_at' => now()]);
    $past = Meeting::create(['class_id' => $class->id, 'title' => 'Past', 'scheduled_at' => now()->subHour()]);

    $results = Meeting::upcoming()->get();

    expect($results->pluck('id'))->toContain($future->id);
    expect($results->pluck('id'))->toContain($nowMeeting->id);
    expect($results->pluck('id'))->not->toContain($past->id);
    expect($results)->toHaveCount(2);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (j) past scope returns meetings < now
// ---------------------------------------------------------------------------

it('past scope returns meetings scheduled before now', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'Past Teacher',
        'email' => 'past-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Past Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PASTMEET',
    ]);

    $past = Meeting::create(['class_id' => $class->id, 'title' => 'Past', 'scheduled_at' => now()->subHour()]);
    $future = Meeting::create(['class_id' => $class->id, 'title' => 'Future', 'scheduled_at' => now()->addHour()]);

    $results = Meeting::past()->get();

    expect($results->pluck('id'))->toContain($past->id);
    expect($results->pluck('id'))->not->toContain($future->id);
    expect($results)->toHaveCount(1);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (k) live scope returns only meetings with url set AND within ±15 min
// ---------------------------------------------------------------------------

it('live scope returns meetings within live window with url set', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'Live Scope Teacher',
        'email' => 'livescope@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Live Scope Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'LIVESCOP',
    ]);

    // Within window, url set — should be live
    $live = Meeting::create(['class_id' => $class->id, 'title' => 'Live', 'scheduled_at' => now(), 'meeting_url' => 'https://meet.google.com/xyz']);
    // Within window, url null — should NOT be live
    $noUrl = Meeting::create(['class_id' => $class->id, 'title' => 'No URL', 'scheduled_at' => now(), 'meeting_url' => null]);
    // Outside window (+20 min), url set — should NOT be live
    $outside = Meeting::create(['class_id' => $class->id, 'title' => 'Outside', 'scheduled_at' => now()->addMinutes(20), 'meeting_url' => 'https://meet.google.com/abc']);

    $results = Meeting::live()->get();

    expect($results->pluck('id'))->toContain($live->id);
    expect($results->pluck('id'))->not->toContain($noUrl->id);
    expect($results->pluck('id'))->not->toContain($outside->id);
    expect($results)->toHaveCount(1);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (l) isLive() returns true at exactly scheduled_at when URL is set
// ---------------------------------------------------------------------------

it('isLive returns true at exactly scheduled_at when url set', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'Exact Teacher',
        'email' => 'exact-live@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Exact Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'EXACTME1',
    ]);

    $meeting = Meeting::create(['class_id' => $class->id, 'title' => 'Exact', 'scheduled_at' => now(), 'meeting_url' => 'https://meet.google.com/xyz']);

    expect($meeting->isLive())->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (m) isLive() returns true at scheduled_at + 14 min when URL is set
// ---------------------------------------------------------------------------

it('isLive returns true 14 min after start', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'Plus14 Teacher',
        'email' => 'plus14@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Plus14 Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PLUS14M1',
    ]);

    $meeting = Meeting::create(['class_id' => $class->id, 'title' => 'Plus14', 'scheduled_at' => now()->parse('2026-08-01 11:46:00'), 'meeting_url' => 'https://meet.google.com/xyz']);

    expect($meeting->isLive())->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (n) isLive() returns false at scheduled_at + 16 min
// ---------------------------------------------------------------------------

it('isLive returns false 16 min after start', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'Plus16 Teacher',
        'email' => 'plus16@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Plus16 Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PLUS16M1',
    ]);

    $meeting = Meeting::create(['class_id' => $class->id, 'title' => 'Plus16', 'scheduled_at' => now()->parse('2026-08-01 11:44:00'), 'meeting_url' => 'https://meet.google.com/xyz']);

    expect($meeting->isLive())->toBeFalse();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (o) isLive() returns false 16 min before start
// ---------------------------------------------------------------------------

it('isLive returns false 16 min before start', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'Minus16 Teacher',
        'email' => 'minus16@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Minus16 Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'MINUS161',
    ]);

    $meeting = Meeting::create(['class_id' => $class->id, 'title' => 'Minus16', 'scheduled_at' => now()->parse('2026-08-01 12:16:00'), 'meeting_url' => 'https://meet.google.com/xyz']);

    expect($meeting->isLive())->toBeFalse();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (p) isLive() returns false when meeting_url is null even within window
// ---------------------------------------------------------------------------

it('isLive returns false when meeting_url is null even within window', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'NoUrl Teacher',
        'email' => 'nourl-meet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'NoUrl Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'NOURLMT1',
    ]);

    $meeting = Meeting::create(['class_id' => $class->id, 'title' => 'No URL', 'scheduled_at' => now(), 'meeting_url' => null]);

    expect($meeting->isLive())->toBeFalse();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// (q) isPast() returns true when scheduled_at is in the past
// ---------------------------------------------------------------------------

it('isPast returns true when scheduled_at is before now', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'PastCheck Teacher',
        'email' => 'pastcheck@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'PastCheck Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PSTCHK01',
    ]);

    $past = Meeting::create(['class_id' => $class->id, 'title' => 'Old', 'scheduled_at' => now()->subHour()]);
    $future = Meeting::create(['class_id' => $class->id, 'title' => 'New', 'scheduled_at' => now()->addHour()]);

    expect($past->isPast())->toBeTrue();
    expect($future->isPast())->toBeFalse();

    Carbon::setTestNow();
});
