<?php

namespace App\Livewire\Student;

use App\Models\Question;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Services\AnswerSelectionWriter;
use App\Services\ExamGradingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ExamTake extends Component
{
    public StudentAttempt $attempt;

    public int $currentIndex = 0;

    /** @var Collection */
    public $questions;

    /** @var array<int, array<int>> */
    public array $selectedOptions = [];

    public function mount(StudentAttempt $attempt): void
    {
        if ($attempt->student_id !== Auth::id()) {
            abort(403);
        }

        $this->attempt = $attempt->load('exam.questions.options');

        $this->questions = $this->attempt->exam->questions()->orderBy('order')->get();

        // Resolve the current question index from the query string.
        $qParam = request()->query('q');
        if ($qParam !== null && is_numeric($qParam)) {
            $index = (int) $qParam;
            if ($index >= 0 && $index < $this->questions->count()) {
                $this->currentIndex = $index;
            } else {
                // Out of range → find first unanswered.
                $this->currentIndex = $this->firstUnansweredIndex();
            }
        } else {
            // No query param → find first unanswered.
            $this->currentIndex = $this->firstUnansweredIndex();
        }

        // Load existing answers so checkboxes/radios are pre-selected.
        $this->loadExistingAnswers();

        // Guard: if the attempt is already graded, redirect to result.
        if ($this->attempt->finished_at !== null) {
            $this->redirect(route('student.exam.result', $this->attempt), navigate: false);
        }
    }

    /**
     * Find the index of the first unanswered question.
     */
    private function firstUnansweredIndex(): int
    {
        $answeredIds = StudentAnswer::where('student_attempt_id', $this->attempt->id)
            ->distinct()
            ->pluck('question_id');

        foreach ($this->questions as $index => $question) {
            if (! $answeredIds->contains($question->id)) {
                return $index;
            }
        }

        // All answered — stay on first.
        return 0;
    }

    /**
     * Load existing selected options for the current question.
     */
    private function loadExistingAnswers(): void
    {
        $answers = StudentAnswer::where('student_attempt_id', $this->attempt->id)->get();
        $this->selectedOptions = [];

        foreach ($answers as $answer) {
            $qid = $answer->question_id;
            if (! isset($this->selectedOptions[$qid])) {
                $this->selectedOptions[$qid] = [];
            }
            $this->selectedOptions[$qid][] = $answer->answer_option_id;
        }
    }

    /**
     * Navigate to the previous question.
     */
    public function previousQuestion(): void
    {
        if ($this->currentIndex > 0) {
            $this->currentIndex--;
        }
    }

    /**
     * Save the current answer and advance to the next question.
     * Delegates to the HTTP POST route so CheckExamTimer middleware runs.
     */
    public function saveAndNext()
    {
        if ($this->finalizeIfExpired()) {
            return redirect()->route('student.exam.result', $this->attempt);
        }

        $question = $this->questions[$this->currentIndex] ?? null;
        if (! $question) {
            return redirect()->route('student.exam.take', $this->attempt);
        }

        // Build the redirect URL manually — the form POSTs to the controller.
        // But for Livewire, we redirect via a full-page navigation.
        $qid = $question->id;
        $opts = $this->selectedOptions[$qid] ?? [];

        // Build a POST-compatible redirect: encode options and redirect.
        // Since we can't POST via redirect, we save inline here.
        $this->persistAnswer($question);

        $nextIndex = $this->currentIndex + 1;

        return redirect()->route('student.exam.take', [
            'attempt' => $this->attempt,
            'q' => $nextIndex,
        ]);
    }

    /**
     * Persist the current answer via the controller's logic (inline).
     */
    private function persistAnswer(Question $question): void
    {
        $selected = $this->selectedOptions[$question->id] ?? [];

        app(AnswerSelectionWriter::class)->replace($this->attempt, $question, $selected);
    }

    /**
     * Finalize the exam — grade and redirect to result.
     */
    public function finalize()
    {
        if ($this->finalizeIfExpired()) {
            return redirect()->route('student.exam.result', $this->attempt);
        }

        // Persist any unsaved answer for the current question first.
        $currentQuestion = $this->questions[$this->currentIndex] ?? null;
        if ($currentQuestion) {
            $this->persistAnswer($currentQuestion);
        }

        $service = new ExamGradingService;
        $service->gradeAttempt($this->attempt->fresh());

        return redirect()->route('student.exam.result', $this->attempt);
    }

    private function finalizeIfExpired(): bool
    {
        $deadline = $this->attempt->started_at->addMinutes($this->attempt->exam->duration_minutes);

        if (! now()->greaterThan($deadline)) {
            return false;
        }

        if ($this->attempt->finished_at === null) {
            $service = new ExamGradingService;
            $service->gradeAttempt($this->attempt->fresh());
            $this->attempt->refresh();
        }

        return true;
    }

    /**
     * The deadline timestamp for the client-side countdown.
     */
    public function deadline(): string
    {
        return $this->attempt->started_at
            ->addMinutes($this->attempt->exam->duration_minutes)
            ->toIso8601String();
    }

    public function render(): View
    {
        // Re-check timer on every render.
        if ($this->attempt->finished_at === null && $this->finalizeIfExpired()) {
            $this->redirect(route('student.exam.result', $this->attempt), navigate: false);

            return view('livewire.student.exam.take');
        }

        $currentQuestion = $this->questions[$this->currentIndex] ?? null;

        return view('livewire.student.exam.take', [
            'currentQuestion' => $currentQuestion,
            'totalQuestions' => $this->questions->count(),
            'currentIndex' => $this->currentIndex,
            'selectedOptions' => $this->selectedOptions,
            'deadline' => $this->deadline(),
            'isLast' => $this->currentIndex >= $this->questions->count() - 1,
        ]);
    }
}
