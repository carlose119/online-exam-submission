<?php

use App\Filament\Resources\TeacherResource\Pages\CreateTeacher;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    // The existing panel permits non-FilamentUser models only in local mode.
    // Configuration and database isolation have already bootstrapped in testing.
    config(['app.env' => 'local']);
});

it('preserves the panel environment boundary on login', function (string $environment, string $role, bool $suspended) {
    config(['app.env' => $environment]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $user = User::factory()->create(['role' => $role, 'suspended_at' => $suspended ? now() : null]);
    $login = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    if ($environment === 'local' && ! $suspended) {
        $login->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
    } else {
        $login->assertHasFormErrors(['email']);
        $this->assertGuest();
    }
})->with(['local', 'production'])->with([
    ['ADMIN', false], ['TEACHER', false], ['TEACHER', true],
]);

it('preserves the panel environment boundary on authenticated HTTP requests', function (string $environment, string $role, bool $suspended) {
    config(['app.env' => $environment]);
    $user = User::factory()->create(['role' => $role, 'suspended_at' => $suspended ? now() : null]);
    $this->actingAs($user)->get('/admin')->assertStatus($environment === 'local' && ! $suspended ? 200 : 403);
})->with(['local', 'production'])->with([
    ['ADMIN', false], ['TEACHER', false], ['TEACHER', true],
]);

it('denies suspended teacher login and permits reactivation', function () {
    $user = User::factory()->create(['role' => 'TEACHER', 'suspended_at' => now()]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    $user->update(['suspended_at' => null]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('denies suspended teacher panel login', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $user = User::factory()->create(['role' => 'TEACHER', 'suspended_at' => now()]);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
});

it('preserves active role login and suspension boundaries', function (string $role, bool $suspended) {
    $user = User::factory()->create(['role' => $role, 'suspended_at' => $suspended ? now() : null]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    $this->get($role === 'STUDENT' ? '/dashboard' : '/admin')->assertOk();
})->with([['TEACHER', false], ['ADMIN', false], ['STUDENT', false], ['ADMIN', true], ['STUDENT', true]]);

it('denies existing suspended teacher sessions on protected routes', function () {
    $user = User::factory()->create(['role' => 'TEACHER']);
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    Auth::forgetGuards();
    $this->get('/admin')->assertOk();
    $user->update(['suspended_at' => now()]);
    Auth::forgetGuards();
    $this->get('/admin')->assertForbidden();
    $this->get('/admin/reports/download/missing.csv')->assertForbidden();
    $user->update(['suspended_at' => null]);
    Auth::forgetGuards();
    $this->get('/admin')->assertOk();
});

it('enforces suspension on hydrated teacher creation actions', function (bool $suspended) {
    $user = User::factory()->create(['role' => 'TEACHER']);
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    Auth::forgetGuards();
    $page = $this->get('/admin/classes/create')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn (string $value) => json_decode(html_entity_decode($value, ENT_QUOTES), true))
        ->first(fn (array $value) => isset($value['data']['data']));
    expect($snapshot)->not->toBeNull();
    $user->update(['suspended_at' => $suspended ? now() : null]);
    Auth::forgetGuards();
    app('livewire')->flushState();
    $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => ['data.title' => 'Unauthorized class'],
            'calls' => [['path' => '', 'method' => 'create', 'params' => []]],
        ]],
    ])->assertStatus($suspended ? 403 : 200);
    expect(SchoolClass::count())->toBe($suspended ? 0 : 1);
})->with([true, false]);

it('retains the selected suspension state when creating a teacher', function (bool $suspended) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create(['role' => 'ADMIN']));
    Livewire::test(CreateTeacher::class)->fillForm([
        'name' => 'New teacher', 'email' => 'new@example.com', 'password' => 'password',
        'suspended_at' => $suspended,
    ])->call('create')->assertHasNoFormErrors();
    $teacher = User::where('email', 'new@example.com')->sole();
    expect($teacher->role)->toBe('TEACHER')
        ->and($teacher->suspended_at !== null)->toBe($suspended);
})->with([true, false]);
