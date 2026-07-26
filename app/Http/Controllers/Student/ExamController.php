<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Models\StudentAttempt;
use App\Services\ExamGradingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExamController extends Controller
{
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

        // Verify the student is subscribed to the exam's class.
        if (! $exam->classroom->students()->where('users.id', $student->id)->exists()) {
            abort(403, 'You are not subscribed to this class.');
        }

        // Enforce 1-attempt constraint.
        if (StudentAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->exists()) {
            abort(403, 'You have already taken this exam.');
        }

        $attempt = StudentAttempt::create([
            'student_id' => $student->id,
            'exam_id' => $exam->id,
            'started_at' => now(),
        ]);

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
    public function answer(Request $request, StudentAttempt $attempt, Question $question): RedirectResponse
    {
        // Guard: question must belong to the attempt's exam.
        if ($question->exam_id !== $attempt->exam_id) {
            abort(404);
        }

        $selectedOptions = $request->input('options', []);

        if (! is_array($selectedOptions)) {
            $selectedOptions = $selectedOptions !== null ? [$selectedOptions] : [];
        }

        // Convert option IDs to integers.
        $selectedOptions = array_map('intval', $selectedOptions);

        // Delete previous answers for this question (safe re-answer).
        StudentAnswer::where('student_attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->delete();

        // Insert the new selection.
        foreach ($selectedOptions as $optionId) {
            StudentAnswer::create([
                'student_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'answer_option_id' => $optionId,
            ]);
        }

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
        $service = new ExamGradingService();
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
