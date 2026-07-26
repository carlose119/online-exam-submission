<?php

namespace App\Livewire\Student;

use App\Models\Exam;
use App\Models\StudentAttempt;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ExamStart extends Component
{
    public Exam $exam;

    public function mount(Exam $exam): void
    {
        $this->exam = $exam->load('classroom');

        // Guard: student must be subscribed to the class.
        if (! $this->exam->classroom->students()->where('users.id', Auth::id())->exists()) {
            abort(403, 'You are not subscribed to this class.');
        }

        // Guard: student must not have an existing attempt.
        if (StudentAttempt::where('student_id', Auth::id())->where('exam_id', $this->exam->id)->exists()) {
            abort(403, 'You have already taken this exam.');
        }
    }

    /**
     * Create the attempt and redirect to the take page.
     */
    public function start(): \Illuminate\Http\RedirectResponse
    {
        $attempt = StudentAttempt::create([
            'student_id' => Auth::id(),
            'exam_id' => $this->exam->id,
            'started_at' => now(),
        ]);

        return redirect()->route('student.exam.take', $attempt);
    }

    public function render(): View
    {
        return view('livewire.student.exam.start', [
            'exam' => $this->exam,
        ]);
    }
}
