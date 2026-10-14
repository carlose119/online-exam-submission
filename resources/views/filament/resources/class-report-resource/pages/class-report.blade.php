<x-filament-panels::page>
@include('filament.resources.class-report-resource.pages.partials.report-styles')
<div class="class-report">
    <header class="report-intro">
        <p class="report-kicker">Class performance</p>
        <h2 class="report-title">{{ $reportData['class']['title'] }}</h2>
        <p class="report-muted">Teacher: {{ $reportData['teacher']['name'] }}</p>
        @if (!empty($reportData['class']['description']))
            <p class="report-description report-muted">{{ $reportData['class']['description'] }}</p>
        @endif
    </header>

    <dl class="report-stats">
        <div class="report-stat">
            <dt>Total Attempts</dt>
            <dd>{{ $reportData['overall_stats']['total_attempts'] }}</dd>
        </div>
        <div class="report-stat">
            <dt>Overall Avg Score</dt>
            <dd>{{ number_format($reportData['overall_stats']['avg_score'], 2) }}</dd>
        </div>
        <div class="report-stat">
            <dt>Overall Pass Rate</dt>
            <dd>{{ number_format($reportData['overall_stats']['pass_rate'], 2) }}%</dd>
        </div>
    </dl>

    {{-- Decorative bars supplement the accessible labels and numeric values. --}}
    @php
        $attemptsScale = max([1, ...array_column(array_column($reportData['exams'], 'chart'), 'attempts_count')]);
    @endphp
    <div class="report-charts">
        <section aria-labelledby="attempts-chart-title" class="report-card">
            <h2 id="attempts-chart-title" class="report-section-title">Attempts per exam</h2>
            <p class="report-help">All matching attempts, including in-progress attempts. Scale: 0–{{ $attemptsScale }} attempts.</p>
            <ul class="report-chart-list" role="list">
                @forelse ($reportData['exams'] as $entry)
                    <li>
                        <p class="report-chart-label">{{ $entry['exam']['title'] }}: {{ $entry['chart']['attempts_count'] }} attempts</p>
                        <div aria-hidden="true" class="report-bar-track">
                            <div data-chart-bar="attempts" style="width: {{ min(100, max(0, $entry['chart']['attempts_count'] / $attemptsScale * 100)) }}%;"></div>
                        </div>
                    </li>
                @empty
                    <li class="report-muted">No exams match the current report filters.</li>
                @endforelse
            </ul>
        </section>
        <section aria-labelledby="pass-rate-chart-title" class="report-card">
            <h2 id="pass-rate-chart-title" class="report-section-title">Scored pass rate per exam</h2>
            <p class="report-help">Only finalized attempts with a recorded score are counted. Scale: 0–100%. Table and export rates use all matching attempts and may differ.</p>
            <ul class="report-chart-list" role="list">
                @forelse ($reportData['exams'] as $entry)
                    <li>
                        <p class="report-chart-label">
                            {{ $entry['exam']['title'] }}:
                            @if ($entry['chart']['scored_pass_rate'] === null)
                                No scored data
                            @else
                                {{ number_format($entry['chart']['scored_pass_rate'], 2) }}% — {{ $entry['chart']['scored_attempts_count'] }} scored attempts
                            @endif
                        </p>
                        @if ($entry['chart']['scored_pass_rate'] !== null)
                            <div aria-hidden="true" class="report-bar-track">
                                <div data-chart-bar="pass-rate" style="width: {{ min(100, max(0, $entry['chart']['scored_pass_rate'])) }}%;"></div>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="report-muted">No exams match the current report filters.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="report-results" aria-labelledby="exam-results-title">
        <h2 id="exam-results-title" class="report-section-title">Exam Results</h2>

        @if (empty($reportData['exams']))
            <p class="report-card report-muted">No exams match the current report filters.</p>
        @else
            @foreach ($reportData['exams'] as $examEntry)
                @php
                    $exam = $examEntry['exam'];
                    $stats = $examEntry['stats'];
                    $attempts = $examEntry['attempts'];
                @endphp

                <details class="report-exam" open>
                    <summary>
                        <div class="report-exam-heading">
                            <span class="report-exam-title">{{ $exam['title'] }}</span>
                            <span class="report-muted">Max: {{ $exam['max_score'] }} pts &middot; {{ $exam['duration_minutes'] }} min</span>
                        </div>
                        <div class="report-exam-stats">
                            <span>{{ $stats['attempts_count'] }} attempts</span>
                            <span>Avg: {{ number_format($stats['avg_score'], 2) }} / {{ $exam['max_score'] }}</span>
                            <span>Pass: {{ number_format($stats['pass_rate'], 2) }}%</span>
                            <span>Median: {{ number_format($stats['median'], 2) }}</span>
                        </div>
                    </summary>

                    @if (empty($attempts))
                        <p class="report-empty">No attempts match the current report filters.</p>
                    @else
                        <div class="report-table-scroll" tabindex="0" role="region" aria-label="{{ $exam['title'] }} student attempts">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Student</th>
                                        <th scope="col">Score</th>
                                        <th scope="col">Finished At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($attempts as $attempt)
                                        <tr>
                                            <td>{{ $attempt['student_name'] }}</td>
                                            <td>{{ $attempt['score_obtained'] }} / {{ $exam['max_score'] }}</td>
                                            <td>{{ $attempt['finished_at'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </details>
            @endforeach
        @endif
    </section>
</div>
</x-filament-panels::page>
