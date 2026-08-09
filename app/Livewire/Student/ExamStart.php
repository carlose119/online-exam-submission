<?php

namespace App\Livewire\Student;

use App\Models\Exam;
use App\Models\StudentAttempt;
use App\Services\ExamAccessGuard;
use App\Services\ExamAllowanceService;
use App\Services\ExamAttemptCreator;
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

        app(ExamAccessGuard::class)->ensureSubscribed($this->exam, Auth::id());

        $attempts = StudentAttempt::where('student_id', Auth::id())->where('exam_id', $this->exam->id);

        if ((clone $attempts)->whereNull('finished_at')->exists()
            || $attempts->count() >= app(ExamAllowanceService::class)->limitsFor($this->exam, Auth::user())->totalAttempts) {
            abort(403, 'You have already taken this exam.');
        }
    }

    /**
     * Create the attempt and redirect to the take page.
     */
    public function start()
    {
        app(ExamAccessGuard::class)->ensureSubscribed($this->exam, Auth::id());

        $attempt = app(ExamAttemptCreator::class)->create($this->exam, Auth::id());

        return redirect()->route('student.exam.take', $attempt);
    }

    public function render(): View
    {
        return view('livewire.student.exam.start', [
            'exam' => $this->exam,
        ]);
    }
}
