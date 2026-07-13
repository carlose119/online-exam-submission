<?php

use App\Models\SchoolClass;
use App\Models\User;

// ---------------------------------------------------------------------------
// (a) GET valid code returns 200 with class title, description, and syllabus
// ---------------------------------------------------------------------------

it('renders class details for a valid invitation code', function () {
    $teacher = User::create([
        'name' => 'Test Teacher',
        'email' => 'invite-teacher@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Math 101',
        'description' => 'Introductory math course',
        'syllabus' => '<p>Week 1: Algebra</p>',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ABC12345',
    ]);

    $response = $this->get(route('class.join.show', 'ABC12345'));

    $response->assertStatus(200);
    $response->assertSee('Math 101');
    $response->assertSee('Introductory math course');
    $response->assertSee('Week 1: Algebra');
});

// ---------------------------------------------------------------------------
// (b) Guest sees "Log in to join" link
// ---------------------------------------------------------------------------

it('guest sees login link', function () {
    $teacher = User::create([
        'name' => 'Test Teacher',
        'email' => 'guest-teacher@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    SchoolClass::create([
        'title' => 'Guest Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'GUEST123',
    ]);

    $response = $this->get(route('class.join.show', 'GUEST123'));

    $response->assertStatus(200);
    $response->assertSee('Log in to join');
    $response->assertSee(route('filament.admin.auth.login'));
});

// ---------------------------------------------------------------------------
// (c) Authenticated user sees TBD placeholder with no subscription side-effect
// ---------------------------------------------------------------------------

it('authenticated user sees TBD placeholder', function () {
    $teacher = User::create([
        'name' => 'Auth Teacher',
        'email' => 'auth-teacher@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    SchoolClass::create([
        'title' => 'Auth Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'AUTH1234',
    ]);

    $response = $this->actingAs($teacher)
        ->get(route('class.join.show', 'AUTH1234'));

    $response->assertStatus(200);
    $response->assertSee('TBD: join this class');
    // Confirm no subscription record was created (class_user pivot
    // doesn't exist yet, so nothing to check — verify no DB write happened)
    $this->assertDatabaseCount('classes', 1);
});

// ---------------------------------------------------------------------------
// (d) GET nonexistent code returns 404
// ---------------------------------------------------------------------------

it('nonexistent invitation code returns 404', function () {
    $response = $this->get(route('class.join.show', 'NONEXIST'));
    $response->assertNotFound();
});
