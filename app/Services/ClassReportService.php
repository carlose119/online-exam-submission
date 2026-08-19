<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Values\ReportFilters;
use Carbon\CarbonImmutable;

class ClassReportService
{
    /**
     * Generate a structured report for a class.
     *
     * Eager-loads exams, attempts, and students in a single pass, then computes
     * per-exam and overall stats (average score, pass rate, median).
     *
     * @return array{
     *     class: array{id: int, title: string, description: ?string},
     *     teacher: array{name: string},
     *     exams: list<array{
     *         exam: array{id: int, title: string, max_score: int, duration_minutes: int},
     *         attempts: list<array{student_name: string, score_obtained: float, finished_at: ?string}>,
     *         stats: array{attempts_count: int, avg_score: float, pass_rate: float, median: float}
     *     }>,
     *     overall_stats: array{total_attempts: int, avg_score: float, pass_rate: float}
     * }
     */
    public function generate(SchoolClass $class, array $filters = ReportFilters::EMPTY): array
    {
        $filters = ReportFilters::from($filters, $class);
        $class->load([
            'teacher',
            'exams' => fn ($query) => $query->when($filters->examIds, fn ($query) => $query->whereKey($filters->examIds)),
            'exams.studentAttempts' => fn ($query) => $query
                ->when($filters->studentIds, fn ($query) => $query->whereIn('student_id', $filters->studentIds))
                ->when($filters->startedFrom, fn ($query) => $query->where('started_at', '>=', CarbonImmutable::parse($filters->startedFrom)->toDateTimeString()))
                ->when($filters->startedUntil, fn ($query) => $query->where('started_at', '<=', CarbonImmutable::parse($filters->startedUntil)->toDateTimeString())),
            'exams.studentAttempts.student',
        ]);

        $passThreshold = (float) config('reports.pass_rate_threshold', 0.6);

        $examsData = [];
        $allScores = [];

        foreach ($class->exams as $exam) {
            $attempts = $exam->studentAttempts->filter(function ($attempt) use ($exam, $filters, $passThreshold): bool {
                if (! $filters->statuses) {
                    return true;
                }

                $status = $attempt->finished_at === null
                    ? 'in_progress'
                    : ((float) $attempt->score_obtained >= $passThreshold * (int) $exam->max_score ? 'passed' : 'failed');

                return in_array($status, $filters->statuses, true);
            });

            $attemptDetails = [];
            $scores = [];

            foreach ($attempts as $attempt) {
                $score = (float) $attempt->score_obtained;
                $scores[] = $score;
                $allScores[] = $score;

                $attemptDetails[] = [
                    'student_name' => $attempt->student?->name ?? 'Unknown',
                    'score_obtained' => $score,
                    'finished_at' => $attempt->finished_at?->toDateTimeString(),
                ];
            }

            // Sort attempts by student name for stable output.
            usort($attemptDetails, fn (array $a, array $b) => $a['student_name'] <=> $b['student_name']);

            $attemptsCount = count($scores);

            $stats = [
                'attempts_count' => $attemptsCount,
                'avg_score' => $attemptsCount > 0 ? $this->average($scores) : 0.0,
                'pass_rate' => $this->passRate($scores, (int) $exam->max_score, $passThreshold),
                'median' => $attemptsCount > 0 ? $this->median($scores) : 0.0,
            ];

            $examsData[] = [
                'exam' => [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'max_score' => (int) $exam->max_score,
                    'duration_minutes' => (int) $exam->duration_minutes,
                ],
                'attempts' => $attemptDetails,
                'stats' => $stats,
            ];
        }

        // Sort exams by title for stable output.
        usort($examsData, fn (array $a, array $b) => $a['exam']['title'] <=> $b['exam']['title']);

        $totalAttempts = count($allScores);

        return [
            'class' => [
                'id' => $class->id,
                'title' => $class->title,
                'description' => $class->description,
            ],
            'teacher' => [
                'name' => $class->teacher?->name ?? 'N/A',
            ],
            'exams' => $examsData,
            'overall_stats' => [
                'total_attempts' => $totalAttempts,
                'avg_score' => $totalAttempts > 0 ? $this->average($allScores) : 0.0,
                'pass_rate' => $this->overallPassRate($examsData),
            ],
        ];
    }

    /**
     * Compute the arithmetic mean of a list of numeric values.
     */
    private function average(array $values): float
    {
        if (count($values) === 0) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * Compute the pass rate as (passing / total) * 100.
     *
     * An attempt passes when score_obtained >= threshold * max_score.
     */
    private function passRate(array $scores, int $maxScore, float $threshold): float
    {
        if (count($scores) === 0) {
            return 0.0;
        }

        $passing = 0;
        $passingScore = $threshold * $maxScore;

        foreach ($scores as $score) {
            if ($score >= $passingScore) {
                $passing++;
            }
        }

        return round(($passing / count($scores)) * 100, 2);
    }

    /**
     * Compute the median of a list of numeric values.
     */
    private function median(array $values): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        sort($values);

        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return round(($values[$mid - 1] + $values[$mid]) / 2, 2);
        }

        return round((float) $values[$mid], 2);
    }

    /**
     * Compute the overall pass rate from per-exam stats.
     *
     * Used as a helper for the overall_stats section where max_score varies
     * across exams.
     */
    public function overallPassRate(array $examsData): float
    {
        $totalPassing = 0;
        $totalAttempts = 0;

        foreach ($examsData as $examEntry) {
            $count = $examEntry['stats']['attempts_count'];
            $passRate = $examEntry['stats']['pass_rate'];

            $totalAttempts += $count;
            $totalPassing += (int) round(($passRate / 100) * $count);
        }

        if ($totalAttempts === 0) {
            return 0.0;
        }

        return round(($totalPassing / $totalAttempts) * 100, 2);
    }
}
