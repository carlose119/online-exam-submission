{{-- The default Filament theme does not include this page's Tailwind utilities. --}}
<style data-report-styles>
    .class-report {
        --report-surface: #fff;
        --report-tint: #eff6ff;
        --report-text: #0f172a;
        --report-muted: #475569;
        --report-border: #e2e8f0;
        --report-track: #e2e8f0;
        --report-accent: #2563eb;
        display: grid;
        gap: 1.5rem;
        min-width: 0;
        color: var(--report-text);
        line-height: 1.6;
    }
    .dark .class-report {
        --report-surface: #18181b;
        --report-tint: #172554;
        --report-text: #f1f5f9;
        --report-muted: #cbd5e1;
        --report-border: #3f3f46;
        --report-track: #3f3f46;
        --report-accent: #60a5fa;
    }
    .class-report .report-intro,
    .class-report .report-card,
    .class-report .report-stat,
    .class-report .report-exam {
        min-width: 0;
        border: 1px solid var(--report-border);
        border-radius: 1rem;
        background: var(--report-surface);
        box-shadow: 0 1px 3px rgb(0 0 0 / 0.04);
    }
    .class-report .report-intro {
        padding: 1.5rem;
        border-inline-start: 4px solid var(--report-accent);
        background: linear-gradient(110deg, var(--report-tint), var(--report-surface));
    }
    .class-report .report-kicker {
        color: var(--report-accent);
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
    .class-report .report-title { margin: 0.25rem 0; font-size: 1.75rem; font-weight: 700; letter-spacing: -0.025em; }
    .class-report .report-description { max-width: 75ch; margin-top: 0.5rem; }
    .class-report .report-muted,
    .class-report .report-help { color: var(--report-muted); font-size: 0.875rem; }
    .class-report .report-title,
    .class-report .report-description,
    .class-report .report-chart-label,
    .class-report .report-exam-title { overflow-wrap: anywhere; }
    .class-report .report-stats,
    .class-report .report-charts { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .class-report .report-stat { padding: 1.25rem 1.5rem; }
    .class-report .report-stat dt { color: var(--report-muted); font-size: 0.875rem; font-weight: 500; }
    .class-report .report-stat dd { margin-top: 0.5rem; font-size: 2rem; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
    .class-report .report-card { padding: 1.5rem; }
    .class-report .report-section-title { font-size: 1.125rem; font-weight: 650; }
    .class-report .report-help { margin-top: 0.375rem; }
    .class-report .report-chart-list { display: grid; gap: 1.25rem; margin-top: 1.5rem; padding: 0; list-style: none; }
    .class-report .report-chart-label { font-size: 0.875rem; font-weight: 600; }
    .class-report .report-bar-track { margin-top: 0.625rem; overflow: hidden; border-radius: 999px; background: var(--report-track); }
    .class-report .report-bar-track > div { height: 0.625rem; border-radius: inherit; background: var(--report-accent); }
    .class-report .report-results { display: grid; gap: 1rem; min-width: 0; }
    .class-report .report-exam > summary {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        cursor: pointer;
        list-style: none;
    }
    .class-report .report-exam > summary::-webkit-details-marker { display: none; }
    .class-report .report-exam > summary::after { content: "+"; margin-inline-start: auto; color: var(--report-muted); font-size: 1.25rem; }
    .class-report .report-exam[open] > summary::after { content: "−"; }
    .class-report .report-exam > summary:hover { background: var(--report-tint); border-radius: 1rem; }
    .class-report .report-exam-heading { display: grid; gap: 0.25rem; min-width: 0; }
    .class-report .report-exam-title { font-weight: 650; }
    .class-report .report-exam-stats { display: flex; flex-wrap: wrap; gap: 0.5rem; color: var(--report-muted); font-size: 0.75rem; }
    .class-report .report-exam-stats > span { padding: 0.25rem 0.625rem; border: 1px solid var(--report-border); border-radius: 0.5rem; }
    .class-report .report-table-scroll { overflow-x: auto; border-top: 1px solid var(--report-border); border-radius: 0 0 1rem 1rem; }
    .class-report .report-table { width: 100%; min-width: 34rem; border-collapse: collapse; text-align: start; font-size: 0.875rem; }
    .class-report .report-table th { background: var(--report-tint); color: var(--report-muted); font-size: 0.75rem; font-weight: 650; }
    .class-report .report-table th,
    .class-report .report-table td { padding: 0.875rem 1.5rem; text-align: start; border-bottom: 1px solid var(--report-border); }
    .class-report .report-table tr:last-child td { border-bottom: 0; }
    .class-report .report-table td:first-child { font-weight: 600; overflow-wrap: anywhere; }
    .class-report .report-table td:not(:first-child) { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .class-report .report-table td:last-child { color: var(--report-muted); }
    .class-report .report-empty { padding: 1.5rem; color: var(--report-muted); font-size: 0.875rem; }
    .class-report .report-exam > summary:focus-visible,
    .class-report .report-table-scroll:focus-visible { outline: 2px solid var(--report-accent); outline-offset: -2px; border-radius: 1rem; }
    @media (min-width: 640px) {
        .class-report .report-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (min-width: 1024px) {
        .class-report .report-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
