<?php

namespace App\Livewire\Student;

use App\Models\StudentAttempt;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ExamResult extends Component
{
    public StudentAttempt $attempt;

    public function mount(StudentAttempt $attempt): void
    {
        if ($attempt->student_id !== Auth::id()) {
            abort(403);
        }

        // If the attempt is not yet graded, redirect to the take page.
        if ($attempt->finished_at === null) {
            $this->redirect(route('student.exam.take', $attempt), navigate: false);
        }

        $this->attempt = $attempt->load('exam');
    }

    public function render(): View
    {
        return view('livewire.student.exam.result', [
            'score' => (float) $this->attempt->score_obtained,
            'maxScore' => (int) $this->attempt->exam->max_score,
            'examTitle' => $this->attempt->exam->title,
        ]);
    }
}
