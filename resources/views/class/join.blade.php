<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $class->title }} — Join Class</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <style>
        body {
            font-family: 'Instrument Sans', sans-serif;
            max-width: 720px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #1a1a2e;
            background: #f8f9fa;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 2rem;
        }
        .card h1 { margin-top: 0; font-size: 1.75rem; }
        .description { color: #64748b; margin-bottom: 1.5rem; }
        .syllabus {
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .action {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            display: inline-block;
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9375rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .btn-primary {
            background: #3b82f6;
            color: #fff;
        }
        .btn-tbd {
            background: #e2e8f0;
            color: #64748b;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $class->title }}</h1>

        @if ($class->description)
            <p class="description">{{ $class->description }}</p>
        @endif

        @if ($class->syllabus)
            <div class="syllabus">
                <h2>Syllabus</h2>
                <div>{!! $class->syllabus !!}</div>
            </div>
        @endif

        <div class="action">
            @if ($isAuthenticated)
                <button class="btn btn-tbd" disabled>TBD: join this class</button>
            @else
                <a href="{{ $loginUrl }}" class="btn btn-primary">Log in to join</a>
            @endif
        </div>
    </div>
</body>
</html>
