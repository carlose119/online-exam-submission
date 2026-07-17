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
        .materials {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        .materials h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        .material-card {
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin-bottom: 0.75rem;
        }
        .material-card a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }
        .material-card a:hover {
            text-decoration: underline;
        }
        .material-card h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.0625rem;
        }
        .meeting-card {
            background: #f8fafc;
        }
        .meeting-time {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        .video-wrapper {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            border-radius: 6px;
            margin-top: 0.5rem;
        }
        .video-wrapper iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
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

        @if($materials->isNotEmpty())
        <div class="materials">
            <h2>Materials</h2>
            @foreach($materials as $material)
                @if($material->type->value === 'FILE')
                <div class="material-card">
                    <a href="{{ Storage::url($material->file_path_or_url) }}" download>
                        {{ $material->title }}
                    </a>
                </div>
                @elseif($material->type->value === 'LINK')
                @php
                    $isYoutube = preg_match('%(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([\w-]{11})%i', $material->file_path_or_url, $matches);
                @endphp
                @if($isYoutube && isset($matches[1]))
                <div class="material-card">
                    <h3>{{ $material->title }}</h3>
                    <div class="video-wrapper">
                        <iframe src="https://www.youtube.com/embed/{{ $matches[1] }}" allowfullscreen></iframe>
                    </div>
                </div>
                @else
                <div class="material-card">
                    <a href="{{ $material->file_path_or_url }}" target="_blank" rel="noopener noreferrer">
                        {{ $material->title }}
                    </a>
                </div>
                @endif
                @elseif($material->type->value === 'MEETING')
                <div class="material-card meeting-card">
                    <h3>{{ $material->extra_metadata['meeting_title'] ?? $material->title }}</h3>
                    @if(isset($material->extra_metadata['scheduled_at']))
                    <p class="meeting-time">{{ \Carbon\Carbon::parse($material->extra_metadata['scheduled_at'])->format('M j, Y g:i A') }}</p>
                    @endif
                    <a href="{{ $material->file_path_or_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                        Join meeting
                    </a>
                </div>
                @endif
            @endforeach
        </div>
        @endif
    </div>
</body>
</html>
