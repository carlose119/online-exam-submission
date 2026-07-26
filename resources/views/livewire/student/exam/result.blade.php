<div class="exam-result-container" style="max-width:640px;margin:2rem auto;padding:0 1rem;">
    <style>
        .exam-result-container { font-family: 'Instrument Sans', sans-serif; color: #1a1a2e; }
        .exam-result-container .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 2rem; text-align: center; }
        .exam-result-container .card h1 { font-size: 1.5rem; margin: 0 0 1rem 0; }
        .exam-result-container .card .score { font-size: 3rem; font-weight: 700; margin: 1.5rem 0; }
        .exam-result-container .card .score-label { color: #64748b; font-size: 1rem; }
        .exam-result-container .card .btn-dashboard { display: inline-block; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 0.75rem 2rem; font-size: 1rem; cursor: pointer; text-decoration: none; margin-top: 1.5rem; }
        .exam-result-container .card .btn-dashboard:hover { background: #1d4ed8; }
    </style>

    <div class="card">
        <h1>{{ $examTitle }}</h1>
        <p class="score-label">Tu calificacion es:</p>
        <p class="score">{{ $score }} / {{ $maxScore }}</p>
        <p style="color:#64748b;font-size:0.875rem;">
            Completado el {{ $attempt->finished_at?->format('d/m/Y H:i') }}
        </p>
        <a href="{{ route('dashboard') }}" class="btn-dashboard">Volver al dashboard</a>
    </div>
</div>
