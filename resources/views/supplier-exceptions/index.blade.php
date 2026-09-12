<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Supplier Exceptions · {{ $partner->name }}</title>
    <style>
        :root {
            --ink: #1c2430;
            --muted: #5b6573;
            --line: #d8dee6;
            --bg: #f4f6f8;
            --card: #ffffff;
            --danger: #9b1c1c;
            --accent: #0f4c5c;
            --warn: #8a5a00;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(15, 76, 92, 0.08), transparent 40%),
                var(--bg);
            line-height: 1.5;
        }
        .wrap { max-width: none; width: 100%; margin: 0; padding: 2rem 1.5rem 3rem; box-sizing: border-box; }
        h1 { font-family: "IBM Plex Serif", Georgia, serif; font-size: 1.75rem; margin: 0 0 0.35rem; }
        .meta { color: var(--muted); margin-bottom: 1.5rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 0.5rem;
            padding: 1.25rem 1.35rem;
            margin-bottom: 1rem;
        }
        .badge {
            display: inline-block;
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
            background: #e8f1f3;
            color: var(--accent);
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-aging {
            background: #fff3d6;
            color: var(--warn);
        }
        .badge-waiting {
            background: #e8f1f3;
            color: var(--accent);
        }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 820px; }
        th, td { text-align: left; padding: 0.6rem 0.45rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        a.view {
            color: var(--accent);
            font-weight: 600;
            text-decoration: none;
        }
        a.view:hover { text-decoration: underline; }
        .footer { color: var(--muted); font-size: 0.85rem; margin-top: 1.5rem; }
        .brand {
            display: flex;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .brand img { height: 32px; width: auto; }
    </style>
    <link rel="icon" href="{{ asset('images/brand/logo-mark.svg') }}" type="image/svg+xml">
</head>
<body>
<div class="wrap">
    <header class="brand">
        <img src="{{ asset('images/brand/logo.svg') }}" alt="TracePharma">
    </header>
    <p class="badge">{{ $cases->count() }} open exception(s)</p>
    <h1>{{ $partner->name }}</h1>
    <p class="meta">Open DSCSA / receiving exceptions awaiting supplier collaboration. Cases leave this list when resolved. Status below is shared by the receiving organization — reply by email is not processed automatically.</p>

    <div class="card">
        @if ($cases->isEmpty())
            <p class="meta" style="margin:0;">No open exceptions for this trading partner.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Case #</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Severity</th>
                        <th>Open for</th>
                        <th>Last notified</th>
                        <th class="num">Open holds</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($cases as $case)
                        @php
                            $daysOpen = $case->created_at
                                ? (int) $case->created_at->startOfDay()->diffInDays(now()->startOfDay())
                                : 0;
                            $isAging = $daysOpen >= (int) $agingDays;
                            $notifiedAt = $lastNotified[$case->id] ?? null;
                            $statusValue = $case->status?->value ?? '';
                        @endphp
                        <tr>
                            <td class="num">{{ $case->id }}</td>
                            <td>{{ $case->type?->name ?? '—' }}</td>
                            <td>{{ $case->title }}</td>
                            <td>
                                @if ($statusValue === 'waiting_partner')
                                    <span class="badge badge-waiting">{{ $case->status?->label() ?? '—' }}</span>
                                @else
                                    {{ $case->status?->label() ?? '—' }}
                                @endif
                            </td>
                            <td>{{ $case->severity?->label() ?? '—' }}</td>
                            <td>
                                {{ $daysOpen }}d
                                @if ($isAging)
                                    <span class="badge badge-aging">Aging</span>
                                @endif
                            </td>
                            <td>{{ $notifiedAt?->toDayDateTimeString() ?? '—' }}</td>
                            <td class="num">{{ $case->open_holds_count }}</td>
                            <td>
                                <a class="view" href="{{ $caseLinks[$case->id] ?? '#' }}">View</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="footer">
        This page is a secure supplier collaboration view. Do not forward beyond authorized trading-partner contacts.
        Individual case links remain available until that exception is resolved.
    </p>
</div>
</body>
</html>
