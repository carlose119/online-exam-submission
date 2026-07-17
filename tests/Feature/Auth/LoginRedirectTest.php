<?php

use App\Models\SchoolClass;
use App\Models\User;

// ---------------------------------------------------------------------------
// Login with a ?redirect query param preserved through the form
// ---------------------------------------------------------------------------

test('login with redirect param returns user to the join page', function () {
    $teacher = User::create([
        'name' => 'Redirect Teacher',
        'email' => 'redirect-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Redirect Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'REDIRECT1',
    ]);

    $student = User::create([
        'name' => 'Redirect Student',
        'email' => 'redirect-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->post('/login', [
        'email' => $student->email,
        'password' => 'password',
        'redirect' => route('class.join.show', 'REDIRECT1'),
    ]);

    $response->assertRedirect(route('class.join.show', 'REDIRECT1'));
    $this->assertAuthenticated();
});

// ---------------------------------------------------------------------------
// Login without ?redirect still goes to the default dashboard
// ---------------------------------------------------------------------------

test('login without redirect param goes to dashboard', function () {
    $student = User::create([
        'name' => 'NoRedirect Student',
        'email' => 'noredirect-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->post('/login', [
        'email' => $student->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
});

// ---------------------------------------------------------------------------
// External redirect URLs are rejected (open-redirect protection)
// ---------------------------------------------------------------------------

test('external redirect url is rejected and falls back to dashboard', function () {
    $student = User::create([
        'name' => 'External Student',
        'email' => 'external-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->post('/login', [
        'email' => $student->email,
        'password' => 'password',
        'redirect' => 'https://evil.com/phishing',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
});

// ---------------------------------------------------------------------------
// Relative redirect URLs are accepted
// ---------------------------------------------------------------------------

test('relative redirect url is accepted', function () {
    $student = User::create([
        'name' => 'Relative Student',
        'email' => 'relative-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->post('/login', [
        'email' => $student->email,
        'password' => 'password',
        'redirect' => '/clase/unirse/RELATIVE1',
    ]);

    $response->assertRedirect('/clase/unirse/RELATIVE1');
    $this->assertAuthenticated();
});
