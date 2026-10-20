<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Evaluation Plan</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 8px; text-align: left; vertical-align: top; }
        .description { white-space: pre-wrap; overflow-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Evaluation Plan</h1>
    <h2>{{ $data['class_title'] }}</h2>
    <p>Teacher: {{ $data['teacher_name'] }}</p>
    <table>
        <thead><tr><th>Exam</th><th>Description</th><th>Max Score</th></tr></thead>
        <tbody>
            @forelse ($data['exams'] as $exam)
                <tr>
                    <td>{{ $exam['title'] }}</td>
                    <td class="description">{{ $exam['description'] }}</td>
                    <td>{{ $exam['max_score'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No exams selected or available.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
