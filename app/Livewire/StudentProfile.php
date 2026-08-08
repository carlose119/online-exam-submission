<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StudentProfile extends Component
{
    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public function mount(): void
    {
        $user = $this->student();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateName(): void
    {
        $user = $this->student();
        $validated = $this->validate(
            ['name' => ['required', 'string', 'max:255']],
            [
                'name.required' => 'Ingresá tu nombre.',
                'name.string' => 'El nombre debe ser texto.',
                'name.max' => 'El nombre no puede superar los 255 caracteres.',
            ],
        );

        $user->name = $validated['name'];
        $user->save();

        session()->flash('status', 'Nombre actualizado.');
    }

    public function updateEmail(): void
    {
        try {
            $user = $this->student();
            $rateLimitKey = 'student-email-change:'.$user->id.':'.sha1((string) request()->ip());

            if (RateLimiter::tooManyAttempts($rateLimitKey, 6)) {
                $this->addError('email', 'Demasiados intentos. Probá de nuevo en '.RateLimiter::availableIn($rateLimitKey).' segundos.');

                return;
            }

            RateLimiter::hit($rateLimitKey, 60);
            $this->email = strtolower(trim($this->email));
            $validated = $this->validate(
                [
                    'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
                    'currentPassword' => ['required', 'current_password'],
                ],
                [
                    'email.required' => 'Ingresá un correo electrónico.',
                    'email.string' => 'El correo electrónico debe ser texto.',
                    'email.email' => 'Ingresá un correo electrónico válido.',
                    'email.max' => 'El correo electrónico no puede superar los 255 caracteres.',
                    'email.unique' => 'Este correo electrónico ya está en uso.',
                    'currentPassword.required' => 'Ingresá tu contraseña actual.',
                    'currentPassword.current_password' => 'La contraseña actual es incorrecta.',
                ],
            );

            if ($validated['email'] === strtolower($user->email)) {
                $this->addError('email', 'El nuevo correo electrónico debe ser diferente del actual.');

                return;
            }

            $user->email = $validated['email'];

            try {
                $user->markEmailAsUnverified();
            } catch (QueryException $exception) {
                if (! $this->isEmailUniqueViolation($exception)) {
                    throw $exception;
                }

                $user->refresh();
                $this->addError('email', 'Este correo electrónico ya está en uso.');

                return;
            }

            $user->sendEmailVerificationNotification();
            $this->redirectRoute('verification.notice');
        } finally {
            $this->reset('currentPassword');
        }
    }

    public function render(): View
    {
        $user = $this->student();

        return view('livewire.student-profile', [
            'user' => $user,
            'subscribedClasses' => $user->subscribedClasses()
                ->orderByPivot('created_at', 'desc')
                ->withCount(['studyMaterials', 'exams', 'meetings'])
                ->with('teacher')
                ->get(),
        ]);
    }

    private function student(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->role === 'STUDENT' && $user->hasVerifiedEmail(), 403);

        return $user;
    }

    private function isEmailUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)
            && str_contains(strtolower($exception->getMessage()), 'email');
    }
}
