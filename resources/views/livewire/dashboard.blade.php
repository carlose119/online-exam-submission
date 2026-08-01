<div>
    <style>
        body {
            font-family: 'Instrument Sans', sans-serif;
            max-width: 960px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #1a1a2e;
            background: #f8f9fa;
        }
        .header {
            margin-bottom: 2rem;
        }
        .header h1 {
            font-size: 1.75rem;
            margin: 0;
        }
        .header .welcome {
            color: #64748b;
            margin-top: 0.25rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.25rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
        }
        .card h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.125rem;
        }
        .card .description {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
        .card .counts {
            display: flex;
            gap: 1rem;
            font-size: 0.8125rem;
            color: #64748b;
        }
        .counts span {
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
        .empty {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }
        .empty p {
            font-size: 1.0625rem;
        }
        .flash {
            background: #dcfce7;
            color: #166534;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
        }
        .logout {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 1rem;
        }
        .logout a, .logout button {
            color: #ef4444;
            font-size: 0.875rem;
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
        }
        .live-badge {
            display: inline-block;
            background: #ef4444;
            color: #fff;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        .join-btn {
            display: inline-block;
            background: #16a34a;
            color: #fff;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .join-btn:hover {
            background: #15803d;
        }
        .meeting-meta {
            color: #64748b;
            font-size: 0.8125rem;
            margin: 0.25rem 0;
        }
    </style>

    <div class="logout">
        <a href="{{ route('profile.show') }}" style="color:#2563eb;text-decoration:none;font-size:0.875rem;margin-right:1rem;">Mi perfil</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </div>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    <div class="header">
        <h1>Welcome, {{ Auth::user()->name }}</h1>
        <p class="welcome">Here are the classes you've joined.</p>
    </div>

    @if ($classes->isEmpty())
        <div class="empty">
            <p>You haven't joined any classes yet.</p>
            <p>Use an invitation link from your teacher to get started.</p>
        </div>
    @else
        <div class="grid">
            @foreach ($classes as $class)
                <div class="card">
                    <h3>{{ $class->title }}</h3>
                    @if ($class->description)
                        <p class="description">{{ Str::limit($class->description, 100) }}</p>
                    @endif
                    <div class="counts">
                        <span>{{ $class->study_materials_count }} {{ Str::plural('material', $class->study_materials_count) }}</span>
                        <span>{{ $class->exams_count }} {{ Str::plural('exam', $class->exams_count) }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Available exams --}}
    @if ($availableExams->isNotEmpty())
        <div style="margin-top:2.5rem;margin-bottom:1rem;">
            <h2 style="font-size:1.25rem;margin:0 0 1rem 0;">Examenes disponibles</h2>
            <div class="grid">
                @foreach ($availableExams as $exam)
                    <div class="card">
                        <h3>{{ $exam->title }}</h3>
                        @if ($exam->description)
                            <p class="description">{{ Str::limit($exam->description, 100) }}</p>
                        @endif
                        <div class="counts" style="margin-bottom:1rem;">
                            <span>{{ $exam->duration_minutes }} min</span>
                            <span>{{ $exam->max_score }} pts</span>
                        </div>
                        <a href="{{ route('student.exam.start', $exam) }}"
                           style="display:inline-block;background:#2563eb;color:#fff;border-radius:6px;padding:0.5rem 1rem;text-decoration:none;font-size:0.875rem;">
                            Iniciar examen
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Completed exams --}}
    @if ($completedAttempts->isNotEmpty())
        <div style="margin-top:2.5rem;margin-bottom:1rem;">
            <h2 style="font-size:1.25rem;margin:0 0 1rem 0;">Examenes completados</h2>
            <div class="grid">
                @foreach ($completedAttempts as $attempt)
                    <div class="card">
                        <h3>{{ $attempt->exam->title }}</h3>
                        <p style="font-size:1.5rem;font-weight:700;margin:0.5rem 0;">
                            {{ (float) $attempt->score_obtained }} / {{ (int) $attempt->exam->max_score }}
                        </p>
                        @if ($attempt->finished_at)
                            <p style="color:#64748b;font-size:0.8125rem;">
                                Completado el {{ $attempt->finished_at->format('d/m/Y H:i') }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Próximas clases en vivo --}}
    <div style="margin-top:2.5rem;margin-bottom:1rem;">
        <h2 style="font-size:1.25rem;margin:0 0 1rem 0;">Próximas clases en vivo</h2>
        @if ($upcomingMeetings->isEmpty())
            <div class="card" style="text-align:center;padding:1.5rem;">
                <p style="color:#64748b;margin:0;">
                    No hay clases en vivo programadas. Tu teacher publicará las próximas sesiones aquí.
                </p>
            </div>
        @else
            <div class="grid">
                @foreach ($upcomingMeetings as $meeting)
                    <div class="card">
                        <h3>{{ $meeting->title }}</h3>
                        <p class="meeting-meta">
                            {{ $meeting->classroom->title }}
                        </p>
                        <p class="meeting-meta">
                            {{ $meeting->scheduled_at->diffForHumans() }} &mdash; {{ $meeting->scheduled_at->format('M j, g:i A T') }}
                        </p>
                        @if ($meeting->isLive())
                            <span class="live-badge">Live now!</span>
                            @if ($meeting->meeting_url)
                                <a href="{{ $meeting->meeting_url }}" target="_blank" rel="noopener" class="join-btn" style="margin-top:0.5rem;">
                                    Unirse a clase
                                </a>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
