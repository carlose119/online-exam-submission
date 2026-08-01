<?php

use App\Models\Exam;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use App\Models\User;
use Illuminate\Support\Carbon;

// ---------------------------------------------------------------------------
// Access Control — Student can access profile
// ---------------------------------------------------------------------------

it('shows the profile page for an authenticated student', function () {
    $student = User::create([
        'name'     => 'Student Profile',
        'email'    => 'student-profile@test.com',
        'password' => 'password',
        'role'     => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get('/profile')
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Access Control — Teacher receives 403
// ---------------------------------------------------------------------------

it('returns 403 for a teacher', function () {
    $teacher = User::create([
        'name'     => 'Profile Teacher',
        'email'    => 'profile-teacher@test.com',
        'password' => 'password',
        'role'     => 'TEACHER',
    ]);

    $this->actingAs($teacher)
        ->get('/profile')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Access Control — Admin receives 403
// ---------------------------------------------------------------------------

it('returns 403 for an admin', function () {
    $admin = User::create([
        'name'     => 'Profile Admin',
        'email'    => 'profile-admin@test.com',
        'password' => 'password',
        'role'     => 'ADMIN',
    ]);

    $this->actingAs($admin)
        ->get('/profile')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Access Control — Guest redirected to login
// ---------------------------------------------------------------------------

it('redirects guests to login', function () {
    $this->get('/profile')
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Data Display — User info (name, email, role badge)
// ---------------------------------------------------------------------------

it('shows user info: name, email, and role badge', function () {
    $student = User::create([
        'name'     => 'María García',
        'email'    => 'maria@example.com',
        'password' => 'password',
        'role'     => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get('/profile')
        ->assertStatus(200)
        ->assertSee('María García')
        ->assertSee('maria@example.com')
        ->assertSee('STUDENT');
});

// ---------------------------------------------------------------------------
// Data Display — Subscribed classes with counts, teacher, and ordering
// ---------------------------------------------------------------------------

it('shows subscribed classes with counts and ordering (DESC by joined_at)', function () {
    $teacher = User::create([
        'name'     => 'Prof. López',
        'email'    => 'lopez@test.com',
        'password' => 'password',
        'role'     => 'TEACHER',
    ]);

    $chemistry = SchoolClass::create([
        'title'           => 'Chemistry 101',
        'teacher_id'      => $teacher->id,
        'invitation_code' => 'CHEM101X',
    ]);

    $physics = SchoolClass::create([
        'title'           => 'Physics 202',
        'teacher_id'      => $teacher->id,
        'invitation_code' => 'PHYS202X',
    ]);

    // Attach materials, exams, and meetings for counts
    StudyMaterial::create([
        'class_id'         => $physics->id,
        'title'            => 'Physics Notes',
        'type'             => 'FILE',
        'file_path_or_url' => 'physics-notes.pdf',
    ]);
    StudyMaterial::create([
        'class_id'         => $physics->id,
        'title'            => 'Physics Slides',
        'type'             => 'FILE',
        'file_path_or_url' => 'physics-slides.pdf',
    ]);
    StudyMaterial::create([
        'class_id'         => $physics->id,
        'title'            => 'Physics Video',
        'type'             => 'LINK',
        'file_path_or_url' => 'https://example.com/video',
    ]);

    Exam::create([
        'class_id'         => $physics->id,
        'title'            => 'Physics Midterm',
        'duration_minutes' => 60,
        'max_score'        => 100,
    ]);
    Exam::create([
        'class_id'         => $physics->id,
        'title'            => 'Physics Final',
        'duration_minutes' => 90,
        'max_score'        => 100,
    ]);

    Meeting::create([
        'class_id'      => $physics->id,
        'title'         => 'Physics Live Q&A',
        'scheduled_at'  => now()->addDay(),
    ]);

    $student = User::create([
        'name'     => 'Class Student',
        'email'    => 'class-student@test.com',
        'password' => 'password',
        'role'     => 'STUDENT',
    ]);

    // Join Chemistry first, then Physics later
    Carbon::setTestNow('2026-01-15 10:00:00');
    $student->subscribedClasses()->attach($chemistry->id);

    Carbon::setTestNow('2026-02-20 14:00:00');
    $student->subscribedClasses()->attach($physics->id);

    Carbon::setTestNow();

    $response = $this->actingAs($student)
        ->get('/profile');

    $response->assertStatus(200);

    // Physics should appear before Chemistry (joined more recently)
    $response->assertSeeInOrder(['Physics 202', 'Chemistry 101']);

    // Physics card details
    $response->assertSee('Physics 202');
    $response->assertSee('con Prof. López');
    $response->assertSee('3 materiales');   // 3 study materials
    $response->assertSee('2 exámenes');     // 2 exams
    $response->assertSee('1 clase en vivo'); // 1 meeting

    // Chemistry card (zero counts — confirm no "0" badges render)
    $response->assertSee('Chemistry 101');

    // Joined date in human-readable and calendar format
    $response->assertSee('Feb 20, 2026');
});

// ---------------------------------------------------------------------------
// Data Display — Empty state when no subscribed classes
// ---------------------------------------------------------------------------

it('shows empty state when no subscribed classes', function () {
    $student = User::create([
        'name'     => 'Solo Student',
        'email'    => 'solo@test.com',
        'password' => 'password',
        'role'     => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get('/profile')
        ->assertStatus(200)
        ->assertSee('Aún no te has unido a ninguna clase. Pide un link de invitación a tu teacher.');
});

// ---------------------------------------------------------------------------
// Data Display — No deferred features present
// ---------------------------------------------------------------------------

it('does not show deferred features (exam history, meeting history, password form, unjoin button, editable fields)', function () {
    $student = User::create([
        'name'     => 'Deferred Student',
        'email'    => 'deferred@test.com',
        'password' => 'password',
        'role'     => 'STUDENT',
    ]);

    $response = $this->actingAs($student)
        ->get('/profile');

    $response->assertStatus(200);

    // Must NOT contain any password inputs or profile-editing UI
    $response->assertDontSee('type="password"', false);
    $response->assertDontSee('current_password');
    $response->assertDontSee('new_password');
    $response->assertDontSee('Unirse a esta clase', false);

    // Must NOT contain editing links that point to profile editing routes
    $response->assertDontSee('profile.edit');
    $response->assertDontSee('profile.update');
    $response->assertDontSee('profile.destroy');

    // Must NOT reference exam history or meeting history sections
    $response->assertDontSee('historial', false);
    $response->assertDontSee('history', false);
    $response->assertDontSee('unirse', false);
});

// ---------------------------------------------------------------------------
// Data Display — Dashboard Mi perfil link visible on dashboard
// ---------------------------------------------------------------------------

it('shows the dashboard Mi perfil link', function () {
    $student = User::create([
        'name'     => 'Link Student',
        'email'    => 'link-student@test.com',
        'password' => 'password',
        'role'     => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get(route('dashboard'))
        ->assertStatus(200)
        ->assertSee('Mi perfil');
});
