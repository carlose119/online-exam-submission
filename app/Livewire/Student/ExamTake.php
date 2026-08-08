<?php

namespace App\Livewire\Student;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Services\AnswerSelectionWriter;
use App\Services\ExamAccessGuard;
use App\Services\ExamGradingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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

    /** @var array<int, string> */
    public array $singleSelections = [];

    /** @var array<int, array<int, string>> */
    public array $multipleSelections = [];

    public function mount(StudentAttempt $attempt): void
    {
        app(ExamAccessGuard::class)->ensureCanTake($attempt, Auth::id());

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
        $answers = StudentAnswer::with('question')
            ->where('student_attempt_id', $this->attempt->id)
            ->get();
        $this->singleSelections = [];
        $this->multipleSelections = [];

        foreach ($answers as $answer) {
            $qid = $answer->question_id;

            if ($answer->question->type === QuestionType::Single) {
                $this->singleSelections[$qid] = (string) $answer->answer_option_id;

                continue;
            }

            $this->multipleSelections[$qid][] = (string) $answer->answer_option_id;
        }
    }

    public function autosaveSingle(int $questionId, int $optionId): void
    {
        if ($this->finalizeIfExpired()) {
            $this->redirect(route('student.exam.result', $this->attempt), navigate: false);

            return;
        }

        $question = $this->currentQuestion($questionId, QuestionType::Single);
        $selected = app(AnswerSelectionWriter::class)->updateOption($this->attempt, $question, $optionId, true);
        $this->singleSelections[$question->id] = $selected[0];
    }

    public function autosaveMultiple(int $questionId, int $optionId, bool $selected): void
    {
        if ($this->finalizeIfExpired()) {
            $this->redirect(route('student.exam.result', $this->attempt), navigate: false);

            return;
        }

        $question = $this->currentQuestion($questionId, QuestionType::Multiple);
        $this->multipleSelections[$question->id] = app(AnswerSelectionWriter::class)
            ->updateOption($this->attempt, $question, $optionId, $selected);
    }

    public function saveAndPrevious()
    {
        return $this->saveAndGoTo($this->currentIndex - 1);
    }

    public function saveAndGoTo(int $target)
    {
        if ($this->finalizeIfExpired()) {
            return redirect()->route('student.exam.result', $this->attempt);
        }

        $question = $this->questions[$this->currentIndex] ?? null;
        if (! $question || $target < 0 || $target >= $this->questions->count()) {
            throw ValidationException::withMessages([
                'target' => 'The requested exam navigation target is invalid.',
            ]);
        }

        $this->persistAnswer($question);

        return redirect()->route('student.exam.take', [
            'attempt' => $this->attempt,
            'q' => $target,
        ]);
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
        if ($question->type === QuestionType::Single) {
            $optionId = $this->singleSelections[$question->id] ?? null;
            $selected = $optionId === null ? [] : [$optionId];
        } else {
            $selected = $this->multipleSelections[$question->id] ?? [];
        }

        app(AnswerSelectionWriter::class)->replace($this->attempt, $question, $selected);
    }

    private function currentQuestion(int $questionId, QuestionType $type): Question
    {
        $question = $this->questions[$this->currentIndex] ?? null;

        if (! $question || $question->id !== $questionId || $question->type !== $type) {
            throw ValidationException::withMessages([
                'options' => 'The option change does not belong to the current question.',
            ]);
        }

        return $question;
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
        app(ExamAccessGuard::class)->ensureCanTake($this->attempt->fresh(), Auth::id());

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
            'answeredQuestionIds' => StudentAnswer::where('student_attempt_id', $this->attempt->id)
                ->distinct()
                ->pluck('question_id')
                ->all(),
            'totalQuestions' => $this->questions->count(),
            'currentIndex' => $this->currentIndex,
            'deadline' => $this->deadline(),
            'isLast' => $this->currentIndex >= $this->questions->count() - 1,
        ]);
    }
}
