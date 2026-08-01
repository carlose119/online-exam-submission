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
    public User $user;

    public function mount(): void
    {
        $this->user = Auth::user();
    }

    public function render(): View
    {
        return view('livewire.student-profile', [
            'subscribedClasses' => $this->user->subscribedClasses()
                ->orderByPivot('created_at', 'desc')
                ->withCount(['studyMaterials', 'exams', 'meetings'])
                ->with('teacher')
                ->get(),
        ]);
    }
}
