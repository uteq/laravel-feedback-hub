<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Feedback Hub</title>
    <style>
        body { margin: 0; background: #f6f7f9; color: #171717; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { max-width: 1120px; margin: 0 auto; padding: 32px 20px; }
        table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        th { color: #52525b; font-size: 12px; text-transform: uppercase; }
        a { color: #135d66; text-decoration: none; font-weight: 650; }
        .badge { display: inline-flex; border-radius: 999px; padding: 3px 8px; background: #edf7f8; color: #135d66; font-size: 12px; font-weight: 650; }
        .muted { color: #71717a; }
        .header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
    </style>
</head>
<body>
<main>
    <div class="header">
        <div>
            <h1>Feedback Hub</h1>
            <p class="muted">Binnengekomen feedback voor {{ config('feedback-hub.project') }}</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Referentie</th>
                <th>Type</th>
                <th>Titel</th>
                <th>Status</th>
                <th>Melder</th>
                <th>Datum</th>
            </tr>
        </thead>
        <tbody>
            @forelse($reports as $report)
                <tr>
                    <td><a href="{{ route('feedback-hub.show', $report) }}">{{ $report->reference }}</a></td>
                    <td>{{ $report->type }}</td>
                    <td>{{ $report->title }}</td>
                    <td><span class="badge">{{ $report->status }}</span></td>
                    <td>{{ $report->reporter_name ?: $report->reporter_email ?: 'Onbekend' }}</td>
                    <td>{{ $report->created_at?->format('d-m-Y H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="muted">Geen feedback gevonden.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 16px;">
        {{ $reports->links() }}
    </div>
</main>
</body>
</html>
