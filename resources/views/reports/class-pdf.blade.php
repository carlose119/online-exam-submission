<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Class Report — {{ $data['class']['title'] }}</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #333;
            margin: 20px;
        }
        h1 {
            font-size: 20px;
            margin-bottom: 5px;
            color: #222;
        }
        h2 {
            font-size: 14px;
            margin-top: 20px;
            margin-bottom: 10px;
            color: #444;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
        }
        .meta {
            margin-bottom: 15px;
            font-size: 12px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            page-break-inside: auto;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #ddd;
        }
        td {
            padding: 5px 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        tr:nth-child(even) {
            background-color: #fafafa;
        }
        .stats-table th {
            text-align: center;
        }
        .stats-table td {
            text-align: center;
        }
        .overall {
            background-color: #eef;
            font-weight: bold;
        }
        .footer {
            margin-top: 20px;
            font-size: 10px;
            color: #999;
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
    </style>
</head>
<body>

    <h1>{{ $data['class']['title'] }}</h1>
    <div class="meta">
        <strong>Teacher:</strong> {{ $data['teacher']['name'] }}<br>
        @if($data['class']['description'])
            <strong>Description:</strong> {{ $data['class']['description'] }}
        @endif
    </div>

    <h2>Per-Exam Breakdown</h2>

    @if(count($data['exams']) === 0)
        <p>No exams found for this class.</p>
    @else
        <table class="stats-table">
            <thead>
                <tr>
                    <th>Exam Title</th>
                    <th>Max Score</th>
                    <th>Duration (min)</th>
                    <th>Attempts</th>
                    <th>Avg Score</th>
                    <th>Pass Rate</th>
                    <th>Median</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['exams'] as $examEntry)
                    <tr>
                        <td>{{ $examEntry['exam']['title'] }}</td>
                        <td>{{ $examEntry['exam']['max_score'] }}</td>
                        <td>{{ $examEntry['exam']['duration_minutes'] }}</td>
                        <td>{{ $examEntry['stats']['attempts_count'] }}</td>
                        <td>{{ $examEntry['stats']['avg_score'] }} / {{ $examEntry['exam']['max_score'] }}</td>
                        <td>{{ $examEntry['stats']['pass_rate'] }}%</td>
                        <td>{{ $examEntry['stats']['median'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @foreach($data['exams'] as $examEntry)
        <h2>{{ $examEntry['exam']['title'] }} — Student Attempts</h2>

        @if(count($examEntry['attempts']) === 0)
            <p>No attempts recorded for this exam.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Score</th>
                        <th>Finished At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($examEntry['attempts'] as $attempt)
                        <tr>
                            <td>{{ $attempt['student_name'] }}</td>
                            <td>{{ $attempt['score_obtained'] }} / {{ $examEntry['exam']['max_score'] }}</td>
                            <td>{{ $attempt['finished_at'] ?? 'N/A' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <h2>Overall Statistics</h2>
    <table class="stats-table">
        <thead>
            <tr>
                <th>Total Attempts</th>
                <th>Overall Avg Score</th>
                <th>Overall Pass Rate</th>
            </tr>
        </thead>
        <tbody>
            <tr class="overall">
                <td>{{ $data['overall_stats']['total_attempts'] }}</td>
                <td>{{ $data['overall_stats']['avg_score'] }}</td>
                <td>{{ $data['overall_stats']['pass_rate'] }}%</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Generated on {{ now()->format('Y-m-d H:i:s') }} &mdash; Online Exam Submission
    </div>

</body>
</html>
