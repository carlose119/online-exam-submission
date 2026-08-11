<div class="exam-take-container" style="max-width:720px;margin:2rem auto;padding:0 1rem;"
     x-data="{
         timeLeft: 0,
         timerDisplay: '',
         init() {
             const deadline = new Date('{{ $deadline }}').getTime();
             const tick = () => {
                 const now = Date.now();
                 const diff = Math.max(0, Math.floor((deadline - now) / 1000));
                 this.timeLeft = diff;
                 const m = Math.floor(diff / 60);
                 const s = diff % 60;
                 this.timerDisplay = m + ':' + String(s).padStart(2, '0');
                 if (diff > 0) { setTimeout(tick, 1000); }
             };
             tick();
         }
     }">
    <style>
        .exam-take-container { font-family: 'Instrument Sans', sans-serif; color: #1a1a2e; }
        .exam-take-container .timer { text-align: center; margin-bottom: 1rem; }
        .exam-take-container .timer .badge { display: inline-block; background: #f1f5f9; padding: 0.5rem 1rem; border-radius: 6px; font-size: 1.125rem; font-weight: 600; }
        .exam-take-container .timer .badge.warning { background: #fef3c7; color: #92400e; }
        .exam-take-container .timer .badge.danger { background: #fee2e2; color: #991b1b; }
        .exam-take-container .progress { color: #64748b; font-size: 0.875rem; text-align: center; margin-bottom: 1.5rem; }
        .exam-take-container .question-nav { margin-bottom: 1.5rem; }
        .exam-take-container .question-nav-list, .exam-take-container .question-legend { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center; }
        .exam-take-container .question-nav-list { margin: 0; padding: 0; list-style: none; }
        .exam-take-container .question-nav-button { min-width: 44px; min-height: 44px; border: 2px solid transparent; border-radius: 6px; font-weight: 700; cursor: pointer; }
        .exam-take-container .question-nav-button:focus-visible { outline: 3px solid #0f172a; outline-offset: 2px; }
        .exam-take-container .question-nav-current { background: #2563eb; color: #fff; border-color: #1e40af; }
        .exam-take-container .question-nav-answered { background: #15803d; color: #fff; border-color: #14532d; }
        .exam-take-container .question-nav-unanswered { background: #fef3c7; color: #78350f; border-color: #92400e; }
        .exam-take-container .question-legend { margin-top: 0.75rem; color: #334155; font-size: 0.8125rem; }
        .exam-take-container .legend-swatch { display: inline-block; width: 0.875rem; height: 0.875rem; margin-right: 0.25rem; border-radius: 3px; vertical-align: -0.1rem; }
        .exam-take-container .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .exam-take-container .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 2rem; }
        .exam-take-container .card h2 { font-size: 1.125rem; margin: 0 0 1rem 0; }
        .exam-take-container .card .q-text { font-size: 1rem; margin-bottom: 1.5rem; }
        .exam-take-container .card .option { display: block; margin-bottom: 0.5rem; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer; }
        .exam-take-container .card .option:hover { background: #f8fafc; }
        .exam-take-container .card .option input { margin-right: 0.5rem; }
        .exam-take-container .nav { display: flex; justify-content: space-between; margin-top: 1.5rem; }
        .exam-take-container .nav button, .exam-take-container .nav a { padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.875rem; text-decoration: none; cursor: pointer; }
        .exam-take-container .nav .btn-prev { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .exam-take-container .nav .btn-next { background: #2563eb; color: #fff; border: none; }
        .exam-take-container .nav .btn-finish { background: #16a34a; color: #fff; border: none; }
        .exam-take-container .nav .btn-next:hover { background: #1d4ed8; }
        .exam-take-container .nav .btn-finish:hover { background: #15803d; }
    </style>

    <div class="timer">
        <span class="badge"
              :class="{ 'warning': timeLeft <= 120 && timeLeft > 60, 'danger': timeLeft <= 60 }"
              x-text="timerDisplay">--:--</span>
    </div>

    <div class="progress">
        Pregunta {{ $currentIndex + 1 }} de {{ $totalQuestions }}
    </div>

    @if ($currentQuestion)
        <div class="card">
            <h2>{{ $currentQuestion->type->getLabel() === 'Single' ? 'Seleccion unica' : 'Seleccion multiple' }}</h2>
            <p class="q-text">{{ $currentQuestion->text }}</p>

            <form method="POST"
                  action="{{ URL::temporarySignedRoute('student.exam.answer', $attempt->deadline()->addMinutes(10), ['attempt' => $attempt, 'question' => $currentQuestion]) }}"
                  wire:submit="{{ $isLast ? 'finalize' : 'saveAndNext' }}">
                @csrf
                <nav class="question-nav" aria-label="Navegador de preguntas">
                    <ol class="question-nav-list">
                        @foreach ($questions as $index => $question)
                            @php($answered = in_array($question->id, $answeredQuestionIds, true))
                            <li>
                                <button type="submit"
                                        class="question-nav-button {{ $index === $currentIndex ? 'question-nav-current' : ($answered ? 'question-nav-answered' : 'question-nav-unanswered') }}"
                                        formaction="{{ URL::temporarySignedRoute('student.exam.answer', $attempt->deadline()->addMinutes(10), ['attempt' => $attempt, 'question' => $currentQuestion, 'target' => $index]) }}"
                                        wire:click.prevent="saveAndGoTo({{ $index }})"
                                        @if ($index === $currentIndex) aria-current="step" @endif>
                                    <span aria-hidden="true">{{ $index + 1 }}</span>
                                    <span class="sr-only">Pregunta {{ $index + 1 }}{{ $index === $currentIndex ? ', actual' : '' }}, {{ $answered ? 'respondida' : 'sin respuesta' }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ol>
                    <div class="question-legend" aria-label="Estados de las preguntas">
                        <span><i class="legend-swatch question-nav-current"></i>Actual</span>
                        <span><i class="legend-swatch question-nav-answered"></i>Respondida</span>
                        <span><i class="legend-swatch question-nav-unanswered"></i>Sin respuesta</span>
                    </div>
                </nav>
                @foreach ($currentQuestion->options as $option)
                    <label class="option">
                        <input type="{{ $currentQuestion->type->value === 'MULTIPLE' ? 'checkbox' : 'radio' }}"
                               name="{{ $currentQuestion->type->value === 'MULTIPLE' ? 'options[]' : 'options' }}"
                               value="{{ $option->id }}"
                               wire:change="{{ $currentQuestion->type->value === 'MULTIPLE'
                                   ? 'autosaveMultiple('.$currentQuestion->id.', '.$option->id.', $event.target.checked)'
                                   : 'autosaveSingle('.$currentQuestion->id.', '.$option->id.')' }}"
                               @checked($currentQuestion->type->value === 'MULTIPLE'
                                   ? in_array((string) $option->id, $multipleSelections[$currentQuestion->id] ?? [], true)
                                   : ($singleSelections[$currentQuestion->id] ?? null) === (string) $option->id)
                               >
                        {{ $option->text }}
                    </label>
                @endforeach

                @error('options')
                    <p style="color:#991b1b;font-size:0.875rem;">{{ $message }}</p>
                @enderror

                <div class="nav">
                    @if ($currentIndex > 0)
                        <button type="submit"
                                formaction="{{ URL::temporarySignedRoute('student.exam.answer', $attempt->deadline()->addMinutes(10), ['attempt' => $attempt, 'question' => $currentQuestion, 'target' => $currentIndex - 1]) }}"
                                class="btn-prev"
                                wire:click.prevent="saveAndPrevious">Anterior</button>
                    @else
                        <span></span>
                    @endif

                    @if ($isLast)
                        <button type="submit" class="btn-finish">Finalizar examen</button>
                    @else
                        <button type="submit" class="btn-next">Siguiente</button>
                    @endif
                </div>
            </form>
        </div>
    @else
        <div class="card" style="text-align:center;padding:3rem;">
            <p>No hay preguntas en este examen.</p>
        </div>
    @endif
</div>
