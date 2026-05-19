<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report->reference }} · Feedback Hub</title>
    <style>
        body { margin: 0; background: #f6f7f9; color: #171717; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { max-width: 1040px; margin: 0 auto; padding: 32px 20px; }
        a { color: #135d66; text-decoration: none; font-weight: 650; }
        .grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 20px; align-items: start; }
        .panel { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; }
        .muted { color: #71717a; }
        .row { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 12px; padding: 8px 0; border-bottom: 1px solid #f0f1f3; font-size: 14px; }
        .row:last-child { border-bottom: 0; }
        pre { overflow: auto; background: #111827; color: #e5e7eb; padding: 14px; border-radius: 8px; font-size: 12px; }
        img { display: block; max-width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; }
        @media (max-width: 860px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <p><a href="{{ route('feedback-hub.index') }}">Terug naar feedback</a></p>
    <h1>{{ $report->reference }}</h1>
    <p class="muted">{{ $report->title }}</p>

    <div class="grid">
        <section class="panel">
            <h2>Feedback</h2>
            @if($report->description)
                <p style="white-space: pre-wrap;">{{ $report->description }}</p>
            @else
                <p class="muted">Geen beschrijving.</p>
            @endif

            @if($report->screenshot_path)
                <h2>Screenshot</h2>
                <img src="{{ route('feedback-hub.screenshot', $report) }}" alt="Feedback screenshot">
            @endif

            <h2>Context</h2>
            <pre>{{ json_encode([
                'element_selector' => $report->element_selector,
                'element_rect' => $report->element_rect,
                'session_data' => $report->session_data,
                'console_errors' => $report->console_errors,
                'network_requests' => $report->network_requests,
                'form_state' => $report->form_state,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>

        <aside class="panel">
            <h2>Details</h2>
            <div class="row"><strong>Project</strong><span>{{ $report->project }}</span></div>
            <div class="row"><strong>Type</strong><span>{{ $report->type }}</span></div>
            <div class="row"><strong>Status</strong><span>{{ $report->status }}</span></div>
            <div class="row"><strong>URL</strong><span><a href="{{ $report->page_url }}" target="_blank" rel="noreferrer">{{ parse_url($report->page_url, PHP_URL_PATH) ?: $report->page_url }}</a></span></div>
            <div class="row"><strong>Melder</strong><span>{{ $report->reporter_name ?: 'Onbekend' }}<br><span class="muted">{{ $report->reporter_email }}</span></span></div>
            <div class="row"><strong>GitHub</strong><span>@if($report->github_issue_url)<a href="{{ $report->github_issue_url }}" target="_blank" rel="noreferrer">#{{ $report->github_issue_number }}</a>@else<span class="muted">Niet gesynchroniseerd</span>@endif</span></div>
            <div class="row"><strong>Linear</strong><span>@if($report->linear_issue_url)<a href="{{ $report->linear_issue_url }}" target="_blank" rel="noreferrer">{{ $report->linear_issue_identifier }}</a>@else<span class="muted">Niet gesynchroniseerd</span>@endif</span></div>
            <div class="row"><strong>Telegram</strong><span>{{ $report->telegram_message_id ?: 'Niet verzonden' }}</span></div>
            @if($report->last_error)
                <div class="row"><strong>Fout</strong><span>{{ $report->last_error }}</span></div>
            @endif
        </aside>
    </div>
</main>
</body>
</html>
