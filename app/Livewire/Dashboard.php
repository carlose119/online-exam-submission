<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * Render the student dashboard with subscribed classes.
     */
    public function render(): View
    {
        $classes = Auth::user()->subscribedClasses()
            ->withCount(['studyMaterials', 'exams'])
            ->get();

        return view('livewire.dashboard', [
            'classes' => $classes,
        ]);
    }
}
