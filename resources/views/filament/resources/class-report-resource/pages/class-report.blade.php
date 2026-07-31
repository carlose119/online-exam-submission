<div class="fi-section space-y-6">
    {{-- Class header --}}
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            {{ $reportData['class']['title'] }}
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Teacher: {{ $reportData['teacher']['name'] }}
        </p>
        @if (!empty($reportData['class']['description']))
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                {{ $reportData['class']['description'] }}
            </p>
        @endif
    </div>

    {{-- Overall stats summary --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Attempts</dt>
            <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                {{ $reportData['overall_stats']['total_attempts'] }}
            </dd>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Overall Avg Score</dt>
            <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                {{ number_format($reportData['overall_stats']['avg_score'], 2) }}
            </dd>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Overall Pass Rate</dt>
            <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                {{ number_format($reportData['overall_stats']['pass_rate'], 2) }}%
            </dd>
        </div>
    </div>

    {{-- Per-exam drill-down --}}
    <div class="space-y-4">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Exam Results</h2>

        @if (empty($reportData['exams']))
            <p class="text-sm text-gray-500 dark:text-gray-400">No exams in this class.</p>
        @else
            @foreach ($reportData['exams'] as $examEntry)
                @php
                    $exam = $examEntry['exam'];
                    $stats = $examEntry['stats'];
                    $attempts = $examEntry['attempts'];
                @endphp

                <details class="group rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800" open>
                    <summary class="flex cursor-pointer items-center justify-between p-4 hover:bg-gray-50 dark:hover:bg-gray-750">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $exam['title'] }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Max: {{ $exam['max_score'] }} pts &middot; {{ $exam['duration_minutes'] }} min
                            </span>
                        </div>
                        <div class="flex items-center gap-4 text-sm text-gray-600 dark:text-gray-300">
                            <span>{{ $stats['attempts_count'] }} attempts</span>
                            <span>Avg: {{ number_format($stats['avg_score'], 2) }} / {{ $exam['max_score'] }}</span>
                            <span>Pass: {{ number_format($stats['pass_rate'], 2) }}%</span>
                            <span>Median: {{ number_format($stats['median'], 2) }}</span>
                        </div>
                    </summary>

                    <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                        @if (empty($attempts))
                            <p class="text-sm text-gray-500 dark:text-gray-400">No attempts yet.</p>
                        @else
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Student</th>
                                        <th class="py-2 pr-4 font-medium text-gray-500 dark:text-gray-400">Score</th>
                                        <th class="py-2 font-medium text-gray-500 dark:text-gray-400">Finished At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($attempts as $attempt)
                                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-750">
                                            <td class="py-2 pr-4 text-gray-900 dark:text-white">
                                                {{ $attempt['student_name'] }}
                                            </td>
                                            <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">
                                                {{ $attempt['score_obtained'] }} / {{ $exam['max_score'] }}
                                            </td>
                                            <td class="py-2 text-gray-500 dark:text-gray-400">
                                                {{ $attempt['finished_at'] ?? '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </details>
            @endforeach
        @endif
    </div>
</div>
