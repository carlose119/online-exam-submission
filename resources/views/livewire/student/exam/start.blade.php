<div class="exam-start-container" style="max-width:640px;margin:2rem auto;padding:0 1rem;">
    <style>
        .exam-start-container { font-family: 'Instrument Sans', sans-serif; color: #1a1a2e; }
        .exam-start-container .back-link { margin-bottom: 1.5rem; }
        .exam-start-container .back-link a { color: #64748b; text-decoration: none; font-size: 0.875rem; }
        .exam-start-container .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 2rem; }
        .exam-start-container .card h1 { font-size: 1.5rem; margin: 0 0 0.5rem 0; }
        .exam-start-container .card .meta { color: #64748b; font-size: 0.875rem; margin-bottom: 1.5rem; }
        .exam-start-container .card .meta span { background: #f1f5f9; padding: 0.25rem 0.5rem; border-radius: 4px; margin-right: 0.5rem; }
        .exam-start-container .card .btn-start { display: inline-block; background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 0.75rem 2rem; font-size: 1rem; cursor: pointer; text-decoration: none; }
        .exam-start-container .card .btn-start:hover { background: #1d4ed8; }
        .exam-start-container .card .warning { background: #fef3c7; color: #92400e; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.875rem; }
    </style>

    <div class="back-link">
        <a href="{{ route('dashboard') }}">&larr; Volver al dashboard</a>
    </div>

    <div class="card">
        <h1>{{ $exam->title }}</h1>

        @if ($exam->description)
            <p style="color:#64748b;margin-bottom:1rem;">{{ $exam->description }}</p>
        @endif

        <div class="meta">
            <span>{{ $exam->duration_minutes }} min</span>
            <span>Puntaje maximo: {{ $exam->max_score }}</span>
            <span>{{ $exam->questions()->count() }} preguntas</span>
        </div>

        <div class="warning">
            Solo tienes un intento. Una vez que comiences, el temporizador
            comenzara a correr y no podras pausarlo.
        </div>

        <button class="btn-start" wire:click="start" wire:loading.attr="disabled">
            <span wire:loading.remove>Comenzar examen</span>
            <span wire:loading>Creando intento...</span>
        </button>
    </div>
</div>
