<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Drawer\Utils;

test('password can be updated', function () {
    $user = User::factory()->create(['role' => 'STUDENT']);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'password-updated')
        ->assertRedirect('/profile');

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
    $this->get('/profile')->assertOk();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create(['role' => 'STUDENT']);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('updatePassword', 'current_password')
        ->assertSessionMissing('_old_input.current_password')
        ->assertSessionMissing('_old_input.password')
        ->assertSessionMissing('_old_input.password_confirmation')
        ->assertRedirect('/profile');
});

it('rejects weak unconfirmed and unchanged passwords', function (array $passwords, string $field) {
    $user = User::factory()->create(['password' => 'current-password', 'role' => 'STUDENT']);

    $response = $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'current-password',
        ...$passwords,
    ]);

    $response
        ->assertSessionHasErrorsIn('updatePassword', $field)
        ->assertSessionMissing('_old_input.current_password')
        ->assertSessionMissing('_old_input.password')
        ->assertSessionMissing('_old_input.password_confirmation');
    expect(Hash::check('current-password', $user->refresh()->password))->toBeTrue();
})->with([
    'weak' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unconfirmed' => [['password' => 'new-password', 'password_confirmation' => 'different-password'], 'password'],
    'unchanged' => [['password' => 'current-password', 'password_confirmation' => 'current-password'], 'password'],
]);

it('explicitly explains that the new password must differ', function () {
    $user = User::factory()->create(['password' => 'current-password', 'role' => 'STUDENT']);

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'current-password',
        'password' => 'current-password',
        'password_confirmation' => 'current-password',
    ])->assertSessionHasErrorsIn('updatePassword', [
        'password' => 'La nueva contraseña debe ser diferente de la actual.',
    ]);
});

it('limits changes to six attempts per minute for each student and IP', function () {
    $user = User::factory()->create(['password' => 'current-password', 'role' => 'STUDENT']);

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect();
    }

    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->put('/password', [
        'current_password' => 'current-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertTooManyRequests()
        ->assertSessionMissing('_old_input.current_password')
        ->assertSessionMissing('_old_input.password')
        ->assertSessionMissing('_old_input.password_confirmation');

    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->put('/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertRedirect();
});

it('allows only authenticated verified students to update passwords', function (User $actor, int $status) {
    $this->actingAs($actor)->put('/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertStatus($status);
})->with([
    'teacher' => fn () => [User::factory()->create(['role' => 'TEACHER']), 403],
    'unverified student' => fn () => [User::factory()->unverified()->create(['role' => 'STUDENT']), 302],
]);

it('redirects guests from the password mutation', function () {
    $this->put('/password')->assertRedirect(route('login'));
});

it('ignores client account identifiers and changes only the authenticated user password', function () {
    $user = User::factory()->create(['password' => 'current-password', 'role' => 'STUDENT']);
    $other = User::factory()->create(['password' => 'other-password', 'role' => 'STUDENT']);

    $this->actingAs($user)->put('/password', [
        'user_id' => $other->id,
        'current_password' => 'current-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertRedirect();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue()
        ->and(Hash::check('other-password', $other->refresh()->password))->toBeTrue();
});

it('keeps the current session and invalidates a previous session after changing password', function () {
    $user = User::factory()->create([
        'name' => 'Original name',
        'password' => 'current-password',
        'role' => 'STUDENT',
    ]);
    $guard = Auth::guard('web');
    $session = app('session')->driver();
    $sessionKey = $guard->getName();
    $oldPasswordHash = $guard->hashPasswordForCookie($user->getAuthPassword());
    $handler = $session->getHandler();

    $previousSessionId = str_repeat('a', 40);
    $currentSessionId = str_repeat('b', 40);

    foreach ([$previousSessionId, $currentSessionId] as $sessionId) {
        $handler->write($sessionId, json_encode([
            $sessionKey => $user->getAuthIdentifier(),
            'password_hash_web' => $oldPasswordHash,
        ]));
    }

    Auth::forgetGuards();
    $mountedProfile = $this->withCookie($session->getName(), $previousSessionId)->get('/profile');
    $snapshot = Utils::extractAttributeDataFromHtml($mountedProfile->getContent(), 'wire:snapshot');
    $livewireUri = app('livewire')->getUpdateUri();

    $preparedUpdate = $this->withCredentials()->withHeader('X-Livewire', 'true')->postJson($livewireUri, [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => ['name' => 'Compromised name'],
            'calls' => [],
        ]],
    ]);
    $preparedUpdate->assertOk();
    $snapshot = json_decode($preparedUpdate->json('components.0.snapshot'), true);
    app('livewire')->flushState();

    Auth::forgetGuards();
    $this->withoutHeader('X-Livewire');
    $this->withCookie($session->getName(), $currentSessionId)->from('/profile')->put('/password', [
        'current_password' => 'current-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertRedirect('/profile');

    Auth::forgetGuards();
    $this->withCookie($session->getName(), $currentSessionId)->get('/profile')->assertOk();
    $this->assertAuthenticatedAs($user);

    Auth::forgetGuards();
    $staleUpdate = $this->withCookie($session->getName(), $previousSessionId)
        ->withHeader('X-Livewire', 'true')
        ->postJson($livewireUri, [
            'components' => [[
                'snapshot' => json_encode($snapshot),
                'updates' => [],
                'calls' => [[
                    'path' => '',
                    'method' => 'updateName',
                    'params' => [],
                ]],
            ]],
        ]);

    $staleUpdate->assertUnauthorized();
    expect($user->refresh()->name)->toBe('Original name');

    Auth::forgetGuards();
    $this->withoutHeader('X-Livewire');
    $this->withCookie($session->getName(), $previousSessionId)->get('/profile')
        ->assertRedirect(route('login'));
    $this->assertGuest();
});
