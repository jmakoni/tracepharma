<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Customer portal · {{ $partner->name }}</title>
    <style>
        :root { --ink: #1c2430; --muted: #5b6573; --line: #d8dee6; --bg: #f4f6f8; --card: #ffffff; --accent: #0f4c5c; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
        .wrap { max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem 3rem; }
        h1 { font-size: 1.6rem; margin: 0 0 0.35rem; }
        .meta { color: var(--muted); margin-bottom: 1.5rem; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 0.5rem; padding: 1.25rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: left; padding: 0.55rem 0.4rem; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-size: 0.75rem; text-transform: uppercase; }
        a.view { color: var(--accent); font-weight: 600; text-decoration: none; }
        a.view:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Outbound EPCIS for {{ $partner->name }}</h1>
        <p class="meta">Documents from the last {{ $retentionYears }} years. This is not the supplier exception portal.</p>
        <div class="card">
            @if ($documents->isEmpty())
                <p>No outbound EPCIS files are available for download.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Created</th>
                            <th>Events</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td>{{ $document->original_filename ?: ('#'.$document->getKey()) }}</td>
                                <td>{{ optional($document->creation_date ?? $document->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                <td>{{ $document->event_count }}</td>
                                <td>
                                    @if ($url = $downloads[(int) $document->getKey()] ?? null)
                                        <a class="view" href="{{ $url }}">Download EPCIS</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</body>
</html>
