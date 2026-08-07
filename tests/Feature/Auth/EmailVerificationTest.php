<?php

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('email verification screen redirects verified users to dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertRedirect(route('dashboard', absolute: false));
});

test('verification notification can be resent to an unverified user', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect()
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

test('verification notification is not resent to a verified user', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('dashboard', absolute: false));

    Notification::assertNothingSent();
});

test('verification notification resend is throttled', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    foreach (range(1, 6) as $_) {
        $this->post(route('verification.send'))->assertRedirect();
    }

    $this->post(route('verification.send'))->assertTooManyRequests();
    Notification::assertSentToTimes($user, VerifyEmail::class, 6);
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatchedTimes(Verified::class, 1);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('repeated valid verification link is idempotent', function () {
    $user = User::factory()->unverified()->create();
    Event::fake([Verified::class]);
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->actingAs($user)->get($verificationUrl)->assertRedirect();
    $this->get($verificationUrl)->assertRedirect();

    Event::assertDispatchedTimes(Verified::class, 1);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with a tampered signature', function () {
    $user = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->actingAs($user)->get($verificationUrl.'&tampered=1')->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with an expired signature', function () {
    $user = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinute(),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->travel(2)->minutes();
    $this->actingAs($user)->get($verificationUrl)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verification link for another authenticated user is rejected', function () {
    $intendedUser = User::factory()->unverified()->create();
    $authenticatedUser = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $intendedUser->id, 'hash' => sha1($intendedUser->email)]
    );

    $this->actingAs($authenticatedUser)->get($verificationUrl)->assertForbidden();

    expect($intendedUser->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($authenticatedUser->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('invitation registration verification returns to discovery before explicit join', function () {
    Notification::fake();
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $class = SchoolClass::create([
        'title' => 'Verified Flow Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'FLOW1234',
    ]);
    $invitationUrl = route('class.join.show', $class->invitation_code, absolute: false);

    $this->get($invitationUrl)
        ->assertOk()
        ->assertSee(route('login', ['redirect' => route('class.join.show', $class->invitation_code)]));

    $this->get(route('login', ['redirect' => $invitationUrl]))
        ->assertOk()
        ->assertSee(route('register', ['redirect' => $invitationUrl]));

    $this->get(route('register', ['redirect' => $invitationUrl]))
        ->assertOk()
        ->assertSee('name="redirect" value="'.$invitationUrl.'"', false);

    $this->post(route('register'), [
        'name' => 'Flow Student',
        'email' => 'flow-student@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'redirect' => $invitationUrl,
    ])->assertRedirect(route('verification.notice', absolute: false))
        ->assertSessionHas('url.intended', $invitationUrl);

    $student = User::where('email', 'flow-student@example.com')->sole();
    $this->get(route('verification.notice'))->assertOk();
    $this->assertDatabaseCount('class_user', 0);

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $student->id, 'hash' => sha1($student->email)]
    );

    $this->get($verificationUrl)->assertRedirect($invitationUrl);
    $this->get($invitationUrl)->assertOk()->assertSee('Unirse a clase');
    $this->assertDatabaseCount('class_user', 0);

    $this->post(route('class.join.action', $class->invitation_code))
        ->assertRedirect(route('dashboard'));
    $this->assertDatabaseHas('class_user', [
        'class_id' => $class->id,
        'user_id' => $student->id,
    ]);
});
