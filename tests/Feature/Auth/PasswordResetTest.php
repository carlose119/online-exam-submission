<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('known and unknown addresses receive the same reset link response', function () {
    Notification::fake();

    $user = User::factory()->create();
    $unknownEmail = 'unknown@example.com';

    $knownResponse = $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => $user->email]);
    $unknownResponse = $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => $unknownEmail]);

    foreach ([$knownResponse, $unknownResponse] as $response) {
        $response
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT))
            ->assertSessionHasNoErrors();
    }

    expect($knownResponse->getStatusCode())->toBe($unknownResponse->getStatusCode())
        ->and($knownResponse->headers->get('Location'))->toBe($unknownResponse->headers->get('Location'));
});

test('reset notification is sent only to a known account', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);
    $this->post('/forgot-password', ['email' => 'unknown@example.com']);

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

test('malformed email is still rejected', function () {
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

test('reset link requests are throttled consistently', function () {
    Notification::fake();

    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $email = $attempt % 2 === 0 ? $user->email : "unknown{$attempt}@example.com";

        $this->post('/forgot-password', ['email' => $email])->assertRedirect();
    }

    $unknownResponse = $this->post('/forgot-password', ['email' => 'excess@example.com']);
    $knownResponse = $this->post('/forgot-password', ['email' => $user->email]);

    $unknownResponse->assertTooManyRequests();
    $knownResponse->assertTooManyRequests();
    expect($knownResponse->getContent())->toBe($unknownResponse->getContent());
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('invalid reset token fails without changing the password', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('reset token expires after the configured sixty minutes', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    expect(config('auth.passwords.users.expire'))->toBe(60);

    $this->travel(61)->minutes();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('successful reset changes credentials and dispatches the reset event', function () {
    Event::fake([PasswordReset::class]);

    $user = User::factory()->create();
    $oldRememberToken = $user->remember_token;
    $token = Password::createToken($user);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));

    $user->refresh();

    expect(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe($oldRememberToken);
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->is($user));

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});
