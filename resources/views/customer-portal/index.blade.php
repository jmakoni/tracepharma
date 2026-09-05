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
        .meta { color: var(--muted); margin-bottom: 1rem; }
        .notice { font-size: 0.85rem; color: var(--muted); margin-bottom: 1.25rem; padding: 0.75rem 1rem; background: #eef3f7; border-radius: 0.35rem; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 0.5rem; padding: 1.25rem; margin-bottom: 1rem; }
        .filters { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; }
        .filters label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.8rem; color: var(--muted); }
        .filters input, .filters select { padding: 0.4rem 0.5rem; border: 1px solid var(--line); border-radius: 0.25rem; font: inherit; }
        .filters button { padding: 0.45rem 0.9rem; background: var(--accent); color: #fff; border: none; border-radius: 0.25rem; cursor: pointer; font: inherit; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { text-align: left; padding: 0.55rem 0.4rem; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-size: 0.75rem; text-transform: uppercase; }
        a.view { color: var(--accent); font-weight: 600; text-decoration: none; }
        a.view:hover { text-decoration: underline; }
        .badge { display: inline-block; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.03em; padding: 0.15rem 0.45rem; border-radius: 999px; background: #e8edf2; color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>EPCIS for {{ $partner->name }}</h1>
        <p class="meta">Inbound and outbound shipping documents from the last {{ $retentionYears }} years. This is not the supplier exception portal.</p>
        <p class="notice">
            Records are retained for {{ $retentionYears }} years per DSCSA record-keeping guidance.
            Download EPCIS XML for your pharmacy system or audit archive. Links expire; request a fresh link from your wholesaler if needed.
        </p>

        <div class="card">
            <form class="filters" method="get" action="">
                @foreach (request()->except(['doc_direction', 'from', 'to', 'po', 'page']) as $key => $value)
                    @if (is_string($value) || is_numeric($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <label>
                    Direction
                    <select name="doc_direction">
                        <option value="" @selected(($filters['direction'] ?? null) === null)>All</option>
                        <option value="inbound" @selected(($filters['direction'] ?? null) === 'inbound')>Inbound</option>
                        <option value="outbound" @selected(($filters['direction'] ?? null) === 'outbound')>Outbound</option>
                    </select>
                </label>
                <label>
                    From
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}">
                </label>
                <label>
                    To
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}">
                </label>
                <label>
                    PO / ASN
                    <input type="search" name="po" value="{{ $filters['po'] ?? '' }}" placeholder="Search PO or ASN">
                </label>
                <button type="submit">Filter</button>
            </form>
        </div>

        <div class="card">
            @if ($documents->isEmpty())
                <p>No EPCIS files match your filters.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Direction</th>
                            <th>Document</th>
                            <th>PO / ASN</th>
                            <th>Created</th>
                            <th>Events</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td><span class="badge">{{ $document->direction }}</span></td>
                                <td>{{ $document->original_filename ?: ('#'.$document->getKey()) }}</td>
                                <td>{{ $document->customer_po ?: ($document->asn_number ?: '—') }}</td>
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
