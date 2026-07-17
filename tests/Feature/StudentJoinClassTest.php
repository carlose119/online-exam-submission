<?php

use App\Models\SchoolClass;
use App\Models\User;

// ---------------------------------------------------------------------------
// Join action creates pivot row and redirects to dashboard
// ---------------------------------------------------------------------------

it('creates class_user pivot row and redirects to dashboard on join', function () {
    $teacher = User::create([
        'name' => 'Join Teacher',
        'email' => 'join-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Join Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'JOIN1234',
    ]);

    $student = User::create([
        'name' => 'Join Student',
        'email' => 'join-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->actingAs($student)
        ->post(route('class.join.action', 'JOIN1234'));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('status');

    $this->assertDatabaseHas('class_user', [
        'class_id' => $class->id,
        'user_id' => $student->id,
    ]);
});

// ---------------------------------------------------------------------------
// Duplicate join is idempotent (no duplicate row, no error)
// ---------------------------------------------------------------------------

it('duplicate join is idempotent', function () {
    $teacher = User::create([
        'name' => 'Dup Teacher',
        'email' => 'dup-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Dup Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'DUP12345',
    ]);

    $student = User::create([
        'name' => 'Dup Student',
        'email' => 'dup-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    // First join
    $this->actingAs($student)
        ->post(route('class.join.action', 'DUP12345'))
        ->assertRedirect(route('dashboard'));

    // Second join — same code, should not create duplicate
    $this->actingAs($student)
        ->post(route('class.join.action', 'DUP12345'))
        ->assertRedirect(route('dashboard'));

    // Exactly one pivot row
    $this->assertDatabaseCount('class_user', 1);
});

// ---------------------------------------------------------------------------
// Join with nonexistent invitation code returns 404
// ---------------------------------------------------------------------------

it('join with nonexistent code returns 404', function () {
    $student = User::create([
        'name' => 'NotFound Student',
        'email' => 'nf-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->post(route('class.join.action', 'NONEXIST'))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Join without auth redirects to login (302)
// ---------------------------------------------------------------------------

it('unauthenticated join redirects to login', function () {
    $this->post(route('class.join.action', 'ANYCODE99'))
        ->assertRedirect(route('login'));
});
