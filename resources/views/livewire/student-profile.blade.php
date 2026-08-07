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
        .profile-header {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #2563eb;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .profile-info h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.5rem;
        }
        .profile-info {
            flex: 1;
            min-width: 0;
        }
        .profile-info .email {
            color: #64748b;
            font-size: 0.9375rem;
            margin: 0;
        }
        .name-form {
            margin-top: 1rem;
        }
        .name-form label {
            display: block;
            margin-bottom: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .name-controls {
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
        }
        .name-controls input {
            width: min(100%, 24rem);
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font: inherit;
        }
        .name-controls input:focus {
            border-color: #2563eb;
            outline: 2px solid #bfdbfe;
            outline-offset: 1px;
        }
        .name-controls button {
            border: 0;
            border-radius: 6px;
            padding: 0.5625rem 0.875rem;
            background: #2563eb;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .name-controls button:disabled {
            cursor: wait;
            opacity: 0.7;
        }
        .field-error {
            margin: 0.375rem 0 0;
            color: #b91c1c;
            font-size: 0.875rem;
        }
        .status-message {
            margin: 0.5rem 0 0;
            color: #166534;
            font-size: 0.875rem;
        }
        .role-badge {
            display: inline-block;
            background: #dbeafe;
            color: #1e40af;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        .section-title {
            font-size: 1.25rem;
            margin: 0 0 1rem 0;
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
        .card h2 {
            margin: 0 0 0.25rem 0;
            font-size: 1.125rem;
        }
        .card .teacher {
            color: #64748b;
            font-size: 0.875rem;
            margin: 0 0 0.75rem 0;
        }
        .card .joined {
            color: #94a3b8;
            font-size: 0.8125rem;
            margin-bottom: 0.75rem;
        }
        .card .counts {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .card .counts .badge {
            background: #f1f5f9;
            color: #334155;
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
        }
        .empty {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .empty p {
            font-size: 1.0625rem;
            margin: 0;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 1.5rem;
            color: #2563eb;
            text-decoration: none;
            font-size: 0.875rem;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .logout {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 1rem;
        }
        .logout button {
            color: #ef4444;
            font-size: 0.875rem;
            background: none;
            border: none;
            cursor: pointer;
        }
        @media (max-width: 640px) {
            .profile-header {
                align-items: flex-start;
                padding: 1.25rem;
            }
            .profile-avatar {
                width: 56px;
                height: 56px;
                font-size: 1.5rem;
            }
            .name-controls {
                flex-direction: column;
            }
        }
    </style>

    <div class="logout">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </div>

    <a href="{{ route('dashboard') }}" class="back-link">&larr; Volver al dashboard</a>

    <div class="profile-header">
        <div class="profile-avatar">{{ substr($user->name, 0, 1) }}</div>
        <div class="profile-info">
            <h1>{{ $user->name }}</h1>
            <p class="email">{{ $user->email }}</p>
            <span class="role-badge">{{ $user->role }}</span>

            <form wire:submit="updateName" class="name-form">
                <label for="profile-name">Nombre</label>
                <div class="name-controls">
                    <input
                        id="profile-name"
                        type="text"
                        wire:model="name"
                        maxlength="255"
                        autocomplete="name"
                        required
                        @error('name') aria-invalid="true" aria-describedby="profile-name-error" @enderror
                    >
                    <button type="submit" wire:loading.attr="disabled" wire:target="updateName">
                        Guardar nombre
                    </button>
                </div>
                @error('name')
                    <p id="profile-name-error" class="field-error" role="alert">{{ $message }}</p>
                @enderror
                @if (session('status'))
                    <p class="status-message" role="status" aria-live="polite">{{ session('status') }}</p>
                @endif
            </form>
        </div>
    </div>

    <h2 class="section-title">Mis clases</h2>

    @if ($subscribedClasses->isEmpty())
        <div class="empty">
            <div class="empty-icon">📚</div>
            <p>Aún no te has unido a ninguna clase. Pide un link de invitación a tu teacher.</p>
        </div>
    @else
        <div class="grid">
            @foreach ($subscribedClasses as $class)
                <div class="card">
                    <h2>{{ $class->title }}</h2>
                    <p class="teacher">con {{ $class->teacher->name }}</p>
                    <p class="joined">
                        Te uniste {{ $class->pivot->created_at->diffForHumans() }}
                        ({{ $class->pivot->created_at->format('M j, Y') }})
                    </p>
                    <div class="counts">
                        <span class="badge">{{ $class->study_materials_count }} {{ $class->study_materials_count == 1 ? 'material' : 'materiales' }}</span>
                        <span class="badge">{{ $class->exams_count }} {{ $class->exams_count == 1 ? 'examen' : 'exámenes' }}</span>
                        <span class="badge">{{ $class->meetings_count }} {{ $class->meetings_count == 1 ? 'clase en vivo' : 'clases en vivo' }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
