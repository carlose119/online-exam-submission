<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    Notification::fake();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $user = User::where('email', 'test@example.com')->sole();

    expect($user->role)->toBe('STUDENT')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and(VerifyEmail::class)->not->toImplement(ShouldQueue::class);
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    $response->assertRedirect(route('verification.notice', absolute: false));
});
