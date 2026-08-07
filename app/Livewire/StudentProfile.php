<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StudentProfile extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->name = $this->student()->name;
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
}
