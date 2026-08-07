<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('unverified student login preserves only invitation discovery as intended', function () {
    $user = User::factory()->unverified()->create(['role' => 'STUDENT']);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'redirect' => '/clase/unirse/LOGIN123',
    ]);

    $response->assertRedirect(route('verification.notice'))
        ->assertSessionHas('url.intended', '/clase/unirse/LOGIN123');
});

test('login rejects external and non-invitation return urls', function (string $redirect) {
    $user = User::factory()->create(['role' => 'STUDENT']);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'redirect' => $redirect,
    ])->assertRedirect(route('dashboard', absolute: false));
})->with([
    'external' => 'https://example.com/clase/unirse/LOGIN123',
    'other local route' => '/profile',
]);
