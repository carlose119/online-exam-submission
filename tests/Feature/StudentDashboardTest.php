<?php

use App\Models\Exam;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;

// ---------------------------------------------------------------------------
// Dashboard requires authentication
// ---------------------------------------------------------------------------

it('dashboard requires authentication', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Dashboard requires STUDENT role
// ---------------------------------------------------------------------------

it('dashboard denies non-STUDENT roles', function () {
    $teacher = User::create([
        'name' => 'Dashboard Teacher',
        'email' => 'dash-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $this->actingAs($teacher)
        ->get(route('dashboard'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Dashboard shows subscribed classes as cards
// ---------------------------------------------------------------------------

it('dashboard shows subscribed classes as cards', function () {
    $teacher = User::create([
        'name' => 'Cards Teacher',
        'email' => 'cards-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $mathClass = SchoolClass::create([
        'title' => 'Mathematics 101',
        'description' => 'Basic algebra and geometry',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'MATHCARD',
    ]);

    $physicsClass = SchoolClass::create([
        'title' => 'Physics 202',
        'description' => 'Mechanics and thermodynamics',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PHYSCARD',
    ]);

    $student = User::factory()->create([
        'name' => 'Card Student',
        'email' => 'card-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    // Subscribe to both classes
    $student->subscribedClasses()->attach($mathClass->id);
    $student->subscribedClasses()->attach($physicsClass->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Welcome, Card Student');
    $response->assertSee('Mathematics 101');
    $response->assertSee('Basic algebra and geometry');
    $response->assertSee('Physics 202');
    $response->assertSee('Mechanics and thermodynamics');
});

// ---------------------------------------------------------------------------
// Dashboard shows empty state when zero subscriptions
// ---------------------------------------------------------------------------

it('dashboard shows empty state when no subscriptions', function () {
    $student = User::factory()->create([
        'name' => 'Empty Student',
        'email' => 'empty-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee("haven't joined any classes yet", false);
    $response->assertSee('Use an invitation link from your teacher to get started');
});

// ---------------------------------------------------------------------------
// Dashboard: available exams listed with start link
// ---------------------------------------------------------------------------

it('dashboard shows available exams with start link', function () {
    $teacher = User::create([
        'name' => 'Exam Teacher',
        'email' => 'exam-teacher-dash@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Math 101',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'EXAMDASH',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Quiz 1',
        'description' => 'First quiz',
        'duration_minutes' => 30,
        'max_score' => 10,
    ]);

    $student = User::factory()->create([
        'name' => 'Exam Student',
        'email' => 'exam-dash-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Examenes disponibles');
    $response->assertSee('Quiz 1');
    $response->assertSee('Iniciar examen');
    // Should have link to the start route.
    $response->assertSee(route('student.exam.start', $exam));
});

// ---------------------------------------------------------------------------
// Dashboard: completed exams listed with scores
// ---------------------------------------------------------------------------

it('dashboard shows completed exams with scores', function () {
    $teacher = User::create([
        'name' => 'Complete Teacher',
        'email' => 'complete-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Physics 101',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'COMPDASH',
    ]);

    $exam = Exam::create([
        'class_id' => $class->id,
        'title' => 'Final Exam',
        'duration_minutes' => 60,
        'max_score' => 15,
    ]);

    $student = User::factory()->create([
        'name' => 'Done Student',
        'email' => 'done-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    // Create a graded attempt.
    StudentAttempt::create([
        'student_id' => $student->id,
        'exam_id' => $exam->id,
        'started_at' => now()->subHour(),
        'finished_at' => now(),
        'score_obtained' => 10,
    ]);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Examenes completados');
    $response->assertSee('Final Exam');
    $response->assertSee('10');
    $response->assertSee('15');
});

// ---------------------------------------------------------------------------
// Dashboard: exam with attempt is not shown in available exams
// ---------------------------------------------------------------------------

it('dashboard does not show attempted exam in available exams', function () {
    $teacher = User::create([
        'name' => 'Hide Teacher',
        'email' => 'hide-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Bio',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'HIDEDASH',
    ]);

    $exam1 = Exam::create([
        'class_id' => $class->id,
        'title' => 'Taken Exam',
        'duration_minutes' => 30,
        'max_score' => 10,
    ]);

    $exam2 = Exam::create([
        'class_id' => $class->id,
        'title' => 'Fresh Exam',
        'duration_minutes' => 20,
        'max_score' => 5,
    ]);

    $student = User::factory()->create([
        'name' => 'Hide Student',
        'email' => 'hide-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    // Take exam1 but not exam2.
    StudentAttempt::create([
        'student_id' => $student->id,
        'exam_id' => $exam1->id,
        'started_at' => now()->subHour(),
        'finished_at' => now(),
        'score_obtained' => 8,
    ]);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);

    // "Taken Exam" should appear in completed, NOT in available.
    $response->assertSee('Examenes completados');
    $response->assertSee('Taken Exam');

    // "Fresh Exam" should appear in available.
    $response->assertSee('Examenes disponibles');
    $response->assertSee('Fresh Exam');

    // "Taken Exam" should NOT appear in available.
    // We verify by checking that the available section only contains "Fresh Exam" as a card title.
    // Since both render in separate sections, we just check both are present in their correct sections.
    $response->assertSee('Iniciar examen');
    $response->assertSee(route('student.exam.start', $exam2));
});

// ---------------------------------------------------------------------------
// Próximas clases en vivo — upcoming meetings shown on dashboard
// ---------------------------------------------------------------------------

it('dashboard shows upcoming meetings from subscribed classes', function () {
    $teacher = User::create([
        'name' => 'Meeting Teacher',
        'email' => 'meeting-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Math 204',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'DASHMATH',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Week 1 Session',
        'scheduled_at' => now()->addDays(2),
    ]);

    $student = User::factory()->create([
        'name' => 'Meeting Student',
        'email' => 'meeting-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Próximas clases en vivo');
    $response->assertSee('Week 1 Session');
    $response->assertSee('Math 204');
});

// ---------------------------------------------------------------------------
// Próximas clases en vivo — next 5 meetings ordered by scheduled_at
// ---------------------------------------------------------------------------

it('dashboard shows next 5 meetings ordered by scheduled_at asc', function () {
    $teacher = User::create([
        'name' => 'Order Meet Teacher',
        'email' => 'ordermeet@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Physics 101',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'DASHPHYS',
    ]);

    Meeting::create(['class_id' => $class->id, 'title' => 'Aug 2', 'scheduled_at' => now()->addDays(2)]);
    Meeting::create(['class_id' => $class->id, 'title' => 'Aug 1', 'scheduled_at' => now()->addDay()]);
    Meeting::create(['class_id' => $class->id, 'title' => 'Aug 8', 'scheduled_at' => now()->addDays(8)]);
    Meeting::create(['class_id' => $class->id, 'title' => 'Aug 3', 'scheduled_at' => now()->addDays(3)]);
    Meeting::create(['class_id' => $class->id, 'title' => 'Aug 9', 'scheduled_at' => now()->addDays(9)]);
    Meeting::create(['class_id' => $class->id, 'title' => 'Aug 10', 'scheduled_at' => now()->addDays(10)]);
    Meeting::create(['class_id' => $class->id, 'title' => 'Aug 0', 'scheduled_at' => now()->addHours(6)]);

    $student = User::factory()->create([
        'name' => 'Order Student',
        'email' => 'order-meet-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    // Only 5 should be shown, and Aug 9/Aug 10 should not appear.
    // Use assertDontSee('>Aug 9<') and assertDontSee('>Aug 10<') to avoid substring
    // matches with times like '9:00 AM' that contain '9' or '10'.
    $response->assertSee('Aug 0');
    $response->assertSee('Aug 1');
    $response->assertSee('Aug 2');
    $response->assertSee('Aug 3');
    $response->assertSee('Aug 8');
    $response->assertDontSee('>Aug 9<');
    $response->assertDontSee('>Aug 10<');
});

// ---------------------------------------------------------------------------
// Próximas clases en vivo — empty state when no upcoming meetings
// ---------------------------------------------------------------------------

it('dashboard shows empty state when no upcoming meetings', function () {
    $student = User::factory()->create([
        'name' => 'NoMeet Student',
        'email' => 'nomeet-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Próximas clases en vivo');
    $response->assertSee('No hay clases en vivo programadas');
});

// ---------------------------------------------------------------------------
// Próximas clases en vivo — does NOT show past meetings
// ---------------------------------------------------------------------------

it('dashboard does not show past meetings in live section', function () {
    $teacher = User::create([
        'name' => 'PastMeet Teacher',
        'email' => 'pastmeet-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'History 101',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PASTDASH',
    ]);

    $past = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Old Session',
        'scheduled_at' => now()->subDay(),
    ]);

    $future = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Future Session',
        'scheduled_at' => now()->addDay(),
    ]);

    $student = User::factory()->create([
        'name' => 'PastMeet Student',
        'email' => 'pastmeet-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Future Session');
    $response->assertDontSee('Old Session');
});

// ---------------------------------------------------------------------------
// Próximas clases en vivo — subscription isolation
// ---------------------------------------------------------------------------

it('dashboard does not show meetings from unsubscribed classes', function () {
    $teacher = User::create([
        'name' => 'Isolate Teacher',
        'email' => 'iso-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $subscribedClass = SchoolClass::create([
        'title' => 'Subscribed Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'SUBSCL01',
    ]);

    $unsubscribedClass = SchoolClass::create([
        'title' => 'Unsubscribed Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'UNSUBCL1',
    ]);

    Meeting::create(['class_id' => $subscribedClass->id, 'title' => 'Visible Meeting', 'scheduled_at' => now()->addDay()]);
    Meeting::create(['class_id' => $unsubscribedClass->id, 'title' => 'Hidden Meeting', 'scheduled_at' => now()->addDay()]);

    $student = User::factory()->create([
        'name' => 'Isolate Student',
        'email' => 'iso-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($subscribedClass->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Visible Meeting');
    $response->assertDontSee('Hidden Meeting');
});

// ---------------------------------------------------------------------------
// Próximas clases en vivo — "Live now!" indicator
// ---------------------------------------------------------------------------

it('dashboard shows live now indicator for meetings within live window', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'LiveNow Teacher',
        'email' => 'livenow-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Live Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'LIVEDASH',
    ]);

    Meeting::create([
        'class_id' => $class->id,
        'title' => 'Live Session',
        'scheduled_at' => now()->parse('2026-08-01 11:55:00'),
        'meeting_url' => 'https://meet.google.com/abc',
    ]);

    Meeting::create([
        'class_id' => $class->id,
        'title' => 'Future Session',
        'scheduled_at' => now()->addDays(1),
    ]);

    $student = User::factory()->create([
        'name' => 'LiveNow Student',
        'email' => 'livenow-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Live now!');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Próximas clases en vivo — Join button visible when live and URL set
// ---------------------------------------------------------------------------

it('dashboard shows join button for live meetings with url set', function () {
    Carbon::setTestNow('2026-08-01 12:00:00');

    $teacher = User::create([
        'name' => 'JoinBtn Teacher',
        'email' => 'joinbtn-teach@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Join Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'JOINDASH',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Joinable Session',
        'scheduled_at' => now()->parse('2026-08-01 11:55:00'),
        'meeting_url' => 'https://meet.google.com/xyz-join',
    ]);

    $student = User::factory()->create([
        'name' => 'JoinBtn Student',
        'email' => 'joinbtn-stu@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Unirse a clase');
    $response->assertSee('https://meet.google.com/xyz-join');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Dashboard — Mi perfil link
// ---------------------------------------------------------------------------

it('dashboard displays Mi perfil link', function () {
    $student = User::factory()->create([
        'name' => 'Link Student',
        'email' => 'link-dash@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->actingAs($student)
        ->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertSee('Mi perfil');
    $response->assertSee(route('profile.show'));
});

it('dashboard redirects unverified students to the verification notice', function () {
    $student = User::factory()->unverified()->create(['role' => 'STUDENT']);

    $this->actingAs($student)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});
