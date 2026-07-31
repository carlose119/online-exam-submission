<?php

namespace App\Livewire;

use App\Models\Exam;
use App\Models\StudentAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * Render the student dashboard with subscribed classes,
     * available exams, and completed exams.
     */
    public function render(): View
    {
        $student = Auth::user();

        $classes = $student->subscribedClasses()
            ->withCount(['studyMaterials', 'exams'])
            ->get();

        // Available exams: exams from subscribed classes that the student
        // has NOT yet attempted.
        $attemptedExamIds = StudentAttempt::where('student_id', $student->id)
            ->pluck('exam_id');

        $availableExams = Exam::whereIn('class_id', $classes->pluck('id'))
            ->whereNotIn('id', $attemptedExamIds)
            ->with('classroom')
            ->get();

        // Completed exams: exams the student has attempted.
        $completedAttempts = StudentAttempt::where('student_id', $student->id)
            ->with('exam')
            ->orderByDesc('finished_at')
            ->get();

        // Upcoming live meetings: next 5 from subscribed classes.
        // Include meetings within the ±15 min live window (not just strictly future).
        $upcomingMeetings = $student->subscribedClasses()
            ->with(['meetings' => function ($q) {
                $q->where('scheduled_at', '>=', now()->subMinutes(15))
                    ->orderBy('scheduled_at', 'asc')
                    ->limit(5);
            }])
            ->get()
            ->flatMap(fn ($class) => $class->meetings)
            ->sortBy('scheduled_at')
            ->take(5)
            ->values();

        return view('livewire.dashboard', [
            'classes' => $classes,
            'availableExams' => $availableExams,
            'completedAttempts' => $completedAttempts,
            'upcomingMeetings' => $upcomingMeetings,
        ]);
    }
}
