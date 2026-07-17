<?php

use App\Models\SchoolClass;
use App\Models\User;

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

    $student = User::create([
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
    $student = User::create([
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
