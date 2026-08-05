<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Question;
use App\Models\StudentAttempt;
use App\Services\AnswerSelectionWriter;
use App\Services\ExamAccessGuard;
use App\Services\ExamAttemptCreator;
use App\Services\ExamGradingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExamController extends Controller
{
    public function __construct(
        private readonly ExamAccessGuard $accessGuard,
        private readonly ExamAttemptCreator $attemptCreator,
    ) {}

    /**
     * Start a new exam attempt.
     *
     * Validates: auth + role:STUDENT (via middleware), class subscription,
     * and no existing attempt. Creates a StudentAttempt and redirects
     * to the take page.
     */
    public function start(Request $request, Exam $exam): RedirectResponse
    {
        $student = Auth::user();

        $this->accessGuard->ensureSubscribed($exam, $student->id);

        $attempt = $this->attemptCreator->create($exam, $student->id);

        return redirect()->route('student.exam.take', $attempt);
    }

    /**
     * Return exam data for the wizard component.
     *
     * Not routed directly — called internally by the ExamTake
     * Livewire component to load the exam structure.
     */
    public function show(StudentAttempt $attempt): array
    {
        $this->accessGuard->ensureCanTake($attempt, Auth::id());

        $attempt->load('exam.questions.options');

        return [
            'attempt' => $attempt,
            'exam' => $attempt->exam,
            'questions' => $attempt->exam->questions()->orderBy('order')->get(),
            'answers' => $attempt->answers()->with('option')->get()->keyBy('question_id'),
        ];
    }

    /**
     * Save the student's answer for a question.
     *
     * Idempotent: deletes existing answers for this (attempt, question)
     * pair, then inserts the new selection. Automatically advances to
     * the next question.
     */
    public function answer(
        Request $request,
        StudentAttempt $attempt,
        Question $question,
        AnswerSelectionWriter $answerWriter,
    ): RedirectResponse {
        $this->accessGuard->ensureCanTake($attempt, Auth::id());

        // Guard: question must belong to the attempt's exam.
        if ($question->exam_id !== $attempt->exam_id) {
            abort(404);
        }

        $selectedOptions = $request->input('options', []);

        if (! is_array($selectedOptions)) {
            $selectedOptions = $selectedOptions !== null ? [$selectedOptions] : [];
        }

        $answerWriter->replace($attempt, $question, $selectedOptions);

        // Compute the next question index.
        $questions = $attempt->exam->questions()->orderBy('order')->get();
        $currentIndex = $questions->search(fn (Question $q) => $q->id === $question->id);
        $nextIndex = $currentIndex + 1;

        return redirect()->route('student.exam.take', [
            'attempt' => $attempt,
            'q' => $nextIndex,
        ]);
    }

    /**
     * Submit the exam attempt for grading.
     *
     * Calls ExamGradingService::gradeAttempt, persists the score
     * and finished_at, then redirects to the result page.
     */
    public function submit(Request $request, StudentAttempt $attempt): RedirectResponse
    {
        $this->accessGuard->ensureCanTake($attempt, Auth::id());

        $service = new ExamGradingService;
        $service->gradeAttempt($attempt);

        return redirect()->route('student.exam.result', $attempt);
    }

    /**
     * Return result data for the result page.
     *
     * Not routed directly — called internally by the ExamResult
     * Livewire component. Redirects to the take page if the
     * attempt is not yet graded.
     */
    public function result(StudentAttempt $attempt): array
    {
        if ($attempt->student_id !== Auth::id()) {
            abort(403);
        }

        if ($attempt->finished_at === null) {
            // If not yet graded, redirect to the take page.
            // (The Livewire component will handle this via its mount method.)
            return ['redirect' => route('student.exam.take', $attempt)];
        }

        $attempt->load('exam');

        return [
            'attempt' => $attempt,
            'exam' => $attempt->exam,
            'score' => (float) $attempt->score_obtained,
            'maxScore' => (int) $attempt->exam->max_score,
        ];
    }
}
