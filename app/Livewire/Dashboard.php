<?php

namespace App\Livewire;

use App\Models\Exam;
use App\Models\StudentAttempt;
use App\Services\ExamAllowanceService;
use App\Services\ExamGradingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * Replace the authenticated student's calendar feed token.
     */
    public function regenerateFeedToken(): void
    {
        Auth::user()->regenerateFeedToken();
    }

    /**
     * Render the student dashboard with subscribed classes,
     * available exams, and completed exams.
     */
    public function render(): View
    {
        $student = Auth::user();

        if ($student->feed_token === null) {
            $student->generateFeedToken();
        }

        $classes = $student->subscribedClasses()
            ->withCount(['studyMaterials', 'exams'])
            ->get();

        $exams = Exam::whereIn('class_id', $classes->pluck('id'))
            ->with([
                'classroom',
                'allowances' => fn ($query) => $query->where('student_id', $student->id),
            ])
            ->get();

        $attemptsQuery = StudentAttempt::where('student_id', $student->id)
            ->with('exam')
            ->orderByDesc('finished_at')
            ->orderByDesc('attempt_number')
            ->orderByDesc('id');
        $attempts = $attemptsQuery->get();
        $expiredAttempts = $attempts->whereNull('finished_at')->filter->isExpired();

        foreach ($expiredAttempts as $expiredAttempt) {
            app(ExamGradingService::class)->gradeAttempt($expiredAttempt);
        }

        if ($expiredAttempts->isNotEmpty()) {
            $attempts = $attemptsQuery->get();
        }

        $attemptsByExam = $attempts->groupBy('exam_id');
        $allowances = app(ExamAllowanceService::class);

        $availableExams = $exams->filter(function (Exam $exam) use ($student, $attemptsByExam, $allowances): bool {
            $examAttempts = $attemptsByExam->get($exam->id, collect());
            $limits = $allowances->limitsFor($exam, $student);

            $exam->setAttribute('effective_duration_minutes', $limits->durationMinutes);
            $exam->setAttribute('total_attempts', $limits->totalAttempts);
            $exam->setAttribute('used_attempts', $examAttempts->count());
            $exam->setAttribute('remaining_attempts', max(0, $limits->totalAttempts - $examAttempts->count()));

            return $examAttempts->whereNull('finished_at')->isEmpty()
                && $examAttempts->count() < $limits->totalAttempts;
        });

        $activeAttempts = $attempts->whereNull('finished_at')->sortByDesc('started_at');
        $completedAttempts = $attempts->whereNotNull('finished_at');

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
            'activeAttempts' => $activeAttempts,
            'completedAttempts' => $completedAttempts,
            'upcomingMeetings' => $upcomingMeetings,
        ]);
    }
}
