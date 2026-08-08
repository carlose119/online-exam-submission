<?php

use App\Livewire\StudentProfile;
use App\Models\Exam;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Access Control — Student can access profile
// ---------------------------------------------------------------------------

it('shows the profile page for an authenticated student', function () {
    $student = User::factory()->create([
        'name' => 'Student Profile',
        'email' => 'student-profile@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get('/profile')
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Access Control — Teacher receives 403
// ---------------------------------------------------------------------------

it('returns 403 for a teacher', function () {
    $teacher = User::create([
        'name' => 'Profile Teacher',
        'email' => 'profile-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $this->actingAs($teacher)
        ->get('/profile')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Access Control — Admin receives 403
// ---------------------------------------------------------------------------

it('returns 403 for an admin', function () {
    $admin = User::create([
        'name' => 'Profile Admin',
        'email' => 'profile-admin@test.com',
        'password' => 'password',
        'role' => 'ADMIN',
    ]);

    $this->actingAs($admin)
        ->get('/profile')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Access Control — Guest redirected to login
// ---------------------------------------------------------------------------

it('redirects guests to login', function () {
    $this->get('/profile')
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Data Display — User info (name, email, role badge)
// ---------------------------------------------------------------------------

it('shows user info: name, email, and role badge', function () {
    $student = User::factory()->create([
        'name' => 'María García',
        'email' => 'maria@example.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get('/profile')
        ->assertStatus(200)
        ->assertSee('María García')
        ->assertSee('maria@example.com')
        ->assertSee('STUDENT');
});

// ---------------------------------------------------------------------------
// Data Display — Subscribed classes with counts, teacher, and ordering
// ---------------------------------------------------------------------------

it('shows subscribed classes with counts and ordering (DESC by joined_at)', function () {
    $teacher = User::create([
        'name' => 'Prof. López',
        'email' => 'lopez@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $chemistry = SchoolClass::create([
        'title' => 'Chemistry 101',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'CHEM101X',
    ]);

    $physics = SchoolClass::create([
        'title' => 'Physics 202',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PHYS202X',
    ]);

    // Attach materials, exams, and meetings for counts
    StudyMaterial::create([
        'class_id' => $physics->id,
        'title' => 'Physics Notes',
        'type' => 'FILE',
        'file_path_or_url' => 'physics-notes.pdf',
    ]);
    StudyMaterial::create([
        'class_id' => $physics->id,
        'title' => 'Physics Slides',
        'type' => 'FILE',
        'file_path_or_url' => 'physics-slides.pdf',
    ]);
    StudyMaterial::create([
        'class_id' => $physics->id,
        'title' => 'Physics Video',
        'type' => 'LINK',
        'file_path_or_url' => 'https://example.com/video',
    ]);

    Exam::create([
        'class_id' => $physics->id,
        'title' => 'Physics Midterm',
        'duration_minutes' => 60,
        'max_score' => 100,
    ]);
    Exam::create([
        'class_id' => $physics->id,
        'title' => 'Physics Final',
        'duration_minutes' => 90,
        'max_score' => 100,
    ]);

    Meeting::create([
        'class_id' => $physics->id,
        'title' => 'Physics Live Q&A',
        'scheduled_at' => now()->addDay(),
    ]);

    $student = User::factory()->create([
        'name' => 'Class Student',
        'email' => 'class-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    // Join Chemistry first, then Physics later
    Carbon::setTestNow('2026-01-15 10:00:00');
    $student->subscribedClasses()->attach($chemistry->id);

    Carbon::setTestNow('2026-02-20 14:00:00');
    $student->subscribedClasses()->attach($physics->id);

    Carbon::setTestNow();

    $response = $this->actingAs($student)
        ->get('/profile');

    $response->assertStatus(200);

    // Physics should appear before Chemistry (joined more recently)
    $response->assertSeeInOrder(['Physics 202', 'Chemistry 101']);

    // Physics card details
    $response->assertSee('Physics 202');
    $response->assertSee('con Prof. López');
    $response->assertSee('3 materiales');   // 3 study materials
    $response->assertSee('2 exámenes');     // 2 exams
    $response->assertSee('1 clase en vivo'); // 1 meeting

    // Chemistry card (zero counts — confirm no "0" badges render)
    $response->assertSee('Chemistry 101');

    // Joined date in human-readable and calendar format
    $response->assertSee('Feb 20, 2026');
});

// ---------------------------------------------------------------------------
// Data Display — Empty state when no subscribed classes
// ---------------------------------------------------------------------------

it('shows empty state when no subscribed classes', function () {
    $student = User::factory()->create([
        'name' => 'Solo Student',
        'email' => 'solo@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get('/profile')
        ->assertStatus(200)
        ->assertSee('Aún no te has unido a ninguna clase. Pide un link de invitación a tu teacher.');
});

// ---------------------------------------------------------------------------
// Data Display — No deferred features present
// ---------------------------------------------------------------------------

it('does not show deferred password, history, deletion, or enrollment actions', function () {
    $student = User::factory()->create([
        'name' => 'Deferred Student',
        'email' => 'deferred@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $response = $this->actingAs($student)
        ->get('/profile');

    $response->assertStatus(200);

    // Must NOT contain password-change or account-deletion UI
    $response->assertDontSee('new_password');
    $response->assertDontSee('Eliminar cuenta');
    $response->assertDontSee('Unirse a esta clase', false);

    // Must NOT contain editing links that point to profile editing routes
    $response->assertDontSee('profile.edit');
    $response->assertDontSee('profile.update');
    $response->assertDontSee('profile.destroy');

    // Must NOT reference exam history or meeting history sections
    $response->assertDontSee('historial', false);
    $response->assertDontSee('history', false);
    $response->assertDontSee('unirse', false);
});

// ---------------------------------------------------------------------------
// Data Display — Dashboard Mi perfil link visible on dashboard
// ---------------------------------------------------------------------------

it('shows the dashboard Mi perfil link', function () {
    $student = User::factory()->create([
        'name' => 'Link Student',
        'email' => 'link-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $this->actingAs($student)
        ->get(route('dashboard'))
        ->assertStatus(200)
        ->assertSee('Mi perfil');
});

it('redirects unverified students to the verification notice', function () {
    $student = User::factory()->unverified()->create(['role' => 'STUDENT']);

    $this->actingAs($student)
        ->get(route('profile.show'))
        ->assertRedirect(route('verification.notice'));
});

it('updates and persists the authenticated student name', function () {
    $student = User::factory()->create(['name' => 'Nombre anterior', 'role' => 'STUDENT']);

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('name', 'Nombre actualizado')
        ->call('updateName')
        ->assertHasNoErrors()
        ->assertSee('Nombre actualizado.');

    expect($student->refresh()->name)->toBe('Nombre actualizado');
});

it('requires a name no longer than 255 characters', function () {
    $student = User::factory()->create(['role' => 'STUDENT']);

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('name', '')
        ->call('updateName')
        ->assertHasErrors(['name' => 'required'])
        ->set('name', str_repeat('a', 256))
        ->call('updateName')
        ->assertHasErrors(['name' => 'max']);

    expect($student->refresh()->name)->not->toBe('');
});

it('preserves invalid name input and escapes it in validation feedback', function () {
    $student = User::factory()->create(['name' => 'Nombre seguro', 'role' => 'STUDENT']);
    $invalidName = '<script>alert("xss")</script>'.str_repeat('a', 256);

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('name', $invalidName)
        ->call('updateName')
        ->assertHasErrors(['name' => 'max'])
        ->assertSet('name', $invalidName)
        ->assertDontSeeHtml('<script>alert("xss")</script>');

    expect($student->refresh()->name)->toBe('Nombre seguro');
});

it('rejects a profile update when the Livewire actor becomes unauthorized', function () {
    $student = User::factory()->create(['name' => 'Nombre seguro', 'role' => 'STUDENT']);
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $component = Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('name', 'Nombre comprometido');

    $this->actingAs($teacher);

    $component->call('updateName')->assertForbidden();

    expect($student->refresh()->name)->toBe('Nombre seguro');
});

it('rejects a profile update when verification or authentication is lost', function () {
    $student = User::factory()->create(['name' => 'Nombre seguro', 'role' => 'STUDENT']);
    $unverified = User::factory()->unverified()->create(['role' => 'STUDENT']);
    $component = Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('name', 'Nombre comprometido');

    $this->actingAs($unverified);
    $component->call('updateName')->assertForbidden();

    Auth::logout();
    Livewire::test(StudentProfile::class)->assertForbidden();

    expect($student->refresh()->name)->toBe('Nombre seguro');
});

it('ignores client account identifiers and only updates the authenticated student', function () {
    $student = User::factory()->create(['name' => 'Estudiante autenticado', 'role' => 'STUDENT']);
    $otherStudent = User::factory()->create(['name' => 'Otra cuenta', 'role' => 'STUDENT']);

    Livewire::actingAs($student)
        ->test(StudentProfile::class, [
            'user' => $otherStudent,
            'userId' => $otherStudent->id,
        ])
        ->set('name', 'Solo mi cuenta')
        ->call('updateName')
        ->assertHasNoErrors();

    expect($student->refresh()->name)->toBe('Solo mi cuenta')
        ->and($otherStudent->refresh()->name)->toBe('Otra cuenta');
});

it('changes email, invalidates verification, sends one notification, and clears the password', function () {
    Notification::fake();
    $student = User::factory()->create([
        'email' => 'old-email@example.com',
        'password' => 'correct-password',
        'role' => 'STUDENT',
    ]);

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('email', '  New-Email@Example.COM  ')
        ->set('currentPassword', 'correct-password')
        ->call('updateEmail')
        ->assertHasNoErrors()
        ->assertSet('email', 'new-email@example.com')
        ->assertSet('currentPassword', '')
        ->assertRedirect(route('verification.notice'));

    $student->refresh();

    expect($student->email)->toBe('new-email@example.com')
        ->and($student->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentToTimes($student, VerifyEmail::class, 1);
});

it('rejects an incorrect current password without retaining it', function () {
    Notification::fake();
    $student = User::factory()->create([
        'email' => 'password-check@example.com',
        'password' => 'correct-password',
        'role' => 'STUDENT',
    ]);

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('email', 'safe-new@example.com')
        ->set('currentPassword', 'wrong-password')
        ->call('updateEmail')
        ->assertHasErrors(['currentPassword' => 'current_password'])
        ->assertSet('email', 'safe-new@example.com')
        ->assertSet('currentPassword', '');

    expect($student->refresh()->email)->toBe('password-check@example.com')
        ->and($student->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

it('rejects an email already used by another account', function () {
    Notification::fake();
    User::factory()->create(['email' => 'taken@example.com']);
    $student = User::factory()->create([
        'email' => 'available@example.com',
        'password' => 'correct-password',
        'role' => 'STUDENT',
    ]);

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('email', ' TAKEN@example.com ')
        ->set('currentPassword', 'correct-password')
        ->call('updateEmail')
        ->assertHasErrors(['email' => 'unique'])
        ->assertSet('email', 'taken@example.com')
        ->assertSet('currentPassword', '');

    expect($student->refresh()->email)->toBe('available@example.com')
        ->and($student->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

it('rejects malformed and overlong email addresses', function (string $email, string $rule) {
    Notification::fake();
    $student = User::factory()->create(['password' => 'correct-password', 'role' => 'STUDENT']);
    $originalEmail = $student->email;

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('email', $email)
        ->set('currentPassword', 'correct-password')
        ->call('updateEmail')
        ->assertHasErrors(['email' => $rule])
        ->assertSet('currentPassword', '');

    expect($student->refresh()->email)->toBe($originalEmail)
        ->and($student->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
})->with([
    'malformed' => ['not-an-email', 'email'],
    'overlong' => [str_repeat('a', 244).'@example.com', 'max'],
]);

it('explicitly rejects an unchanged email without invalidating verification', function () {
    Notification::fake();
    $student = User::factory()->create([
        'email' => 'same@example.com',
        'password' => 'correct-password',
        'role' => 'STUDENT',
    ]);

    Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('email', ' SAME@EXAMPLE.COM ')
        ->set('currentPassword', 'correct-password')
        ->call('updateEmail')
        ->assertHasErrors(['email'])
        ->assertSee('El nuevo correo electrónico debe ser diferente del actual.')
        ->assertSet('currentPassword', '');

    expect($student->refresh()->email)->toBe('same@example.com')
        ->and($student->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

it('limits email change attempts to six per minute for the student and IP', function () {
    Notification::fake();
    $student = User::factory()->create(['password' => 'correct-password', 'role' => 'STUDENT']);
    $component = Livewire::actingAs($student)->test(StudentProfile::class);

    foreach (range(1, 6) as $attempt) {
        $component
            ->set('email', "attempt-{$attempt}@example.com")
            ->set('currentPassword', 'wrong-password')
            ->call('updateEmail')
            ->assertHasErrors(['currentPassword' => 'current_password'])
            ->assertSet('currentPassword', '');
    }

    $component
        ->set('email', 'seventh@example.com')
        ->set('currentPassword', 'correct-password')
        ->call('updateEmail')
        ->assertHasErrors(['email'])
        ->assertSee('Demasiados intentos.')
        ->assertSet('currentPassword', '');

    expect($student->refresh()->email)->not->toBe('seventh@example.com')
        ->and($student->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

it('changes only the re-resolved authenticated student email', function () {
    Notification::fake();
    $student = User::factory()->create(['password' => 'correct-password', 'role' => 'STUDENT']);
    $otherStudent = User::factory()->create(['email' => 'other@example.com', 'role' => 'STUDENT']);

    Livewire::actingAs($student)
        ->test(StudentProfile::class, ['userId' => $otherStudent->id])
        ->set('email', 'authenticated-only@example.com')
        ->set('currentPassword', 'correct-password')
        ->call('updateEmail')
        ->assertRedirect(route('verification.notice'));

    expect($student->refresh()->email)->toBe('authenticated-only@example.com')
        ->and($student->hasVerifiedEmail())->toBeFalse()
        ->and($otherStudent->refresh()->email)->toBe('other@example.com')
        ->and($otherStudent->hasVerifiedEmail())->toBeTrue();
    Notification::assertSentToTimes($student, VerifyEmail::class, 1);
    Notification::assertNotSentTo($otherStudent, VerifyEmail::class);
});

it('rejects an email change when the Livewire actor becomes unauthorized', function () {
    Notification::fake();
    $student = User::factory()->create([
        'email' => 'protected@example.com',
        'password' => 'correct-password',
        'role' => 'STUDENT',
    ]);
    $teacher = User::factory()->create(['role' => 'TEACHER']);
    $component = Livewire::actingAs($student)
        ->test(StudentProfile::class)
        ->set('email', 'compromised@example.com')
        ->set('currentPassword', 'correct-password');

    $this->actingAs($teacher);
    $component->call('updateEmail')->assertForbidden();

    expect($student->refresh()->email)->toBe('protected@example.com')
        ->and($student->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});
