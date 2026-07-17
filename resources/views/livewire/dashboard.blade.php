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
    </style>

    <div class="logout">
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
</div>
