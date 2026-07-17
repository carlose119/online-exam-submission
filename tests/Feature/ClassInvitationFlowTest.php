<?php

use App\Enums\StudyMaterialType;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
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
    // The login link now points to the Breeze login with ?redirect
    $response->assertSee(route('login', ['redirect' => route('class.join.show', 'GUEST123')]));
});

// ---------------------------------------------------------------------------
// (c) Authenticated user sees TBD placeholder with no subscription side-effect
// ---------------------------------------------------------------------------

it('authenticated user sees join form not TBD', function () {
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
    $response->assertSee('Unirse a clase');
    $response->assertDontSee('TBD: join this class');
    // Confirm the form action points to the correct join route
    $response->assertSee(route('class.join.action', 'AUTH1234'));
});

// ---------------------------------------------------------------------------
// (d) Guest login link carries ?redirect param
// ---------------------------------------------------------------------------

it('guest login link carries redirect param', function () {
    $teacher = User::create([
        'name' => 'Guest Teacher',
        'email' => 'guest-link@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    SchoolClass::create([
        'title' => 'Guest Link Test',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'LINK1234',
    ]);

    $response = $this->get(route('class.join.show', 'LINK1234'));

    $response->assertStatus(200);
    $response->assertSee('Log in to join');
    // The login link now points to the Breeze login with ?redirect
    $response->assertSee(route('login', ['redirect' => route('class.join.show', 'LINK1234')]));
});

// ---------------------------------------------------------------------------
// (d) GET nonexistent code returns 404
// ---------------------------------------------------------------------------

it('nonexistent invitation code returns 404', function () {
    $response = $this->get(route('class.join.show', 'NONEXIST'));
    $response->assertNotFound();
});

// ---------------------------------------------------------------------------
// (e) Materials section renders after TBD block when class has materials
// ---------------------------------------------------------------------------

it('renders Materials section after TBD block when class has materials', function () {
    $teacher = User::create([
        'name' => 'Materials Flow Teacher',
        'email' => 'matflow@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Materials Flow Class',
        'description' => 'Class with materials',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'MATFLOW1',
    ]);

    StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'PDF Notes',
        'type' => StudyMaterialType::File,
        'file_path_or_url' => 'materials/1/notes.pdf',
    ]);

    StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'YouTube Intro',
        'type' => StudyMaterialType::Link,
        'file_path_or_url' => 'https://www.youtube.com/watch?v=xyz123abc45',
    ]);

    $response = $this->actingAs($teacher)
        ->get(route('class.join.show', 'MATFLOW1'));

    $response->assertStatus(200);
    $response->assertSee('Materials Flow Class');
    // Materials section renders with the heading
    $response->assertSee('Materials');
    // Both materials are visible
    $response->assertSee('PDF Notes');
    $response->assertSee('YouTube Intro');
    // YouTube embed works
    $response->assertSee('youtube.com/embed/xyz123abc45', false);
    // Join form is now active (not TBD)
    $response->assertSee('Unirse a clase');
});
