<?php

namespace App\Http\Middleware;

use App\Models\StudentAttempt;
use App\Services\ExamAccessGuard;
use App\Services\ExamGradingService;
use Closure;
use Illuminate\Http\Request;

class CheckExamTimer
{
    public function __construct(private readonly ExamAccessGuard $accessGuard) {}

    /**
     * Enforce the strict exam timer.
     *
     * If the timer has expired, auto-submits (grades the attempt,
     * sets finished_at) and redirects to the result page.
     * Otherwise, passes through to the next middleware.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Resolve the attempt from the route parameter.
        $attempt = $request->route('attempt');

        if (! $attempt instanceof StudentAttempt) {
            return $next($request);
        }

        $this->accessGuard->ensureCanTake($attempt, $request->user()->id);
        if ($attempt->isExpired()) {
            // Timer expired — auto-submit.
            if ($attempt->finished_at === null) {
                $service = new ExamGradingService;
                $service->gradeAttempt($attempt);
            }

            return redirect()->route('student.exam.result', $attempt);
        }

        return $next($request);
    }
}
