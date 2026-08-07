<?php

use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// 1. Auth gate — guest is redirected to login
// ---------------------------------------------------------------------------

it('redirects guest to login', function () {
    $teacher = User::create([
        'name' => 'Ical Teacher',
        'email' => 'ical-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Ical Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ICALCL01',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Test Meeting',
        'scheduled_at' => now()->addDay(),
    ]);

    $this->get(route('meetings.ics', $meeting))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// 2. Role gate — non-student (teacher) gets 403
// ---------------------------------------------------------------------------

it('denies non-student role', function () {
    $teacher = User::create([
        'name' => 'Role Teacher',
        'email' => 'rolet-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Role Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ROLECL01',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Role Meeting',
        'scheduled_at' => now()->addDay(),
    ]);

    $this->actingAs($teacher)
        ->get(route('meetings.ics', $meeting))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 3. Subscription gate — unsubscribed student gets 403
// ---------------------------------------------------------------------------

it('denies unsubscribed student', function () {
    $teacher = User::create([
        'name' => 'SubGate Teacher',
        'email' => 'subgate-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'SubGate Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'SUBGATE1',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'SubGate Meeting',
        'scheduled_at' => now()->addDay(),
    ]);

    $unsubscribed = User::create([
        'name' => 'Unsub Student',
        'email' => 'unsub-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $this->actingAs($unsubscribed)
        ->get(route('meetings.ics', $meeting))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 4. Happy path — subscribed student gets valid .ics with all fields
// ---------------------------------------------------------------------------

it('returns valid ics for subscribed student', function () {
    Carbon::setTestNow('2026-08-03 12:00:00');

    $teacher = User::create([
        'name' => 'Ana Pérez',
        'email' => 'ana@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Algebra',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ALGEBRA1',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Algebra Review',
        'scheduled_at' => now()->parse('2026-08-10 15:00:00'),
        'duration_minutes' => 90,
        'meeting_url' => 'https://meet.example.com/abc',
        'agenda' => 'Review chapters 1-3',
    ]);

    $student = User::create([
        'name' => 'Sub Student',
        'email' => 'sub-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('meetings.ics', $meeting));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $response->assertHeader(
        'Content-Disposition',
        'attachment; filename="meeting-'.$meeting->id.'.ics"'
    );

    // Check all seven fields are present
    $response->assertSee('BEGIN:VCALENDAR');
    $response->assertSee('VERSION:2.0');
    $response->assertSee('PRODID:-//online-exam-submission//ical-export//EN');
    $response->assertSee('UID:meeting-'.$meeting->id.'@online-exam-submission.test');
    $response->assertSee('DTSTART:20260810T150000Z');
    $response->assertSee('DTEND:20260810T163000Z');
    $response->assertSee('SUMMARY:Algebra Review');
    $response->assertSee('DESCRIPTION:Review chapters 1-3');
    $response->assertSee('LOCATION:https://meet.example.com/abc');
    $response->assertSee('ORGANIZER;CN=Ana Pérez:mailto:ana@example.com');
    $response->assertSee('END:VCALENDAR');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 5. Edge case — null duration defaults to 60 minutes
// ---------------------------------------------------------------------------

it('defaults null duration to 60 minutes', function () {
    $teacher = User::create([
        'name' => 'NullDur Teacher',
        'email' => 'nulldur-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'NullDur Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'NULLDUR1',
    ]);

    // The migration default is 60; omit duration_minutes so the DB default applies.
    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'No Duration',
        'scheduled_at' => now()->parse('2026-08-10 15:00:00'),
        'meeting_url' => 'https://meet.example.com/xyz',
        'agenda' => 'Some agenda',
    ]);

    $student = User::create([
        'name' => 'NullDur Student',
        'email' => 'nulldur-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('meetings.ics', $meeting));

    $response->assertStatus(200);
    $response->assertSee('DTSTART:20260810T150000Z');
    $response->assertSee('DTEND:20260810T160000Z'); // +60 min default
});

// ---------------------------------------------------------------------------
// 6. Edge case — null agenda and meeting_url don't crash
// ---------------------------------------------------------------------------

it('handles null agenda and meeting_url', function () {
    $teacher = User::create([
        'name' => 'NullFields Teacher',
        'email' => 'nullfields-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'NullFields Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'NULLFLD1',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Minimal Meeting',
        'scheduled_at' => now()->addDay(),
        'meeting_url' => null,
        'agenda' => null,
    ]);

    $student = User::create([
        'name' => 'NullFields Student',
        'email' => 'nullfields-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('meetings.ics', $meeting));

    $response->assertStatus(200);
    $response->assertSee('DESCRIPTION:');
    $response->assertSee('LOCATION:');
    // No 500 error
});

// ---------------------------------------------------------------------------
// 7. Scope boundary — no RRULE in exported .ics
// ---------------------------------------------------------------------------

it('contains no RRULE', function () {
    $teacher = User::create([
        'name' => 'NoRRule Teacher',
        'email' => 'norrle-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'NoRRule Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'NORRULE1',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Single Instance',
        'scheduled_at' => now()->addDay(),
    ]);

    $student = User::create([
        'name' => 'NoRRule Student',
        'email' => 'norrle-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('meetings.ics', $meeting));

    $response->assertStatus(200);
    $response->assertDontSee('RRULE');
});

// ---------------------------------------------------------------------------
// 8. Dashboard renders "Download .ics" link
// ---------------------------------------------------------------------------

it('renders download ics link on dashboard', function () {
    $teacher = User::create([
        'name' => 'DashIcal Teacher',
        'email' => 'dashical-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'DashIcal Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'DASHICAL',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Upcoming Session',
        'scheduled_at' => now()->addDay(),
    ]);

    $student = User::factory()->create([
        'name' => 'DashIcal Student',
        'email' => 'dashical-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Download .ics');
    $response->assertSee(route('meetings.ics', $meeting));
});
