<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Recall notice · {{ $request->title }}</title>
    <style>
        :root {
            --ink: #1c2430;
            --muted: #5b6573;
            --line: #d8dee6;
            --bg: #f4f6f8;
            --card: #ffffff;
            --danger: #9b1c1c;
            --accent: #0f4c5c;
            --success: #166534;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(155, 28, 28, 0.06), transparent 40%),
                var(--bg);
            line-height: 1.5;
        }
        .wrap { max-width: 640px; margin: 0 auto; padding: 2rem 1.5rem 3rem; }
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
            background: #fde8e8;
            color: var(--danger);
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-success {
            background: #dcfce7;
            color: var(--success);
        }
        dl { margin: 0; display: grid; grid-template-columns: 8rem 1fr; gap: 0.5rem 1rem; font-size: 0.95rem; }
        dt { color: var(--muted); font-weight: 600; }
        dd { margin: 0; }
        .btn {
            display: inline-block;
            margin-top: 1.25rem;
            padding: 0.65rem 1.25rem;
            border: none;
            border-radius: 0.375rem;
            background: var(--accent);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { filter: brightness(1.05); }
        .footer { color: var(--muted); font-size: 0.85rem; margin-top: 1.5rem; }
        .brand { display: flex; align-items: center; margin-bottom: 1.25rem; }
        .brand img { height: 32px; width: auto; }
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            background: #dcfce7;
            color: var(--success);
            font-weight: 600;
        }
    </style>
    <link rel="icon" href="{{ asset('images/brand/logo-mark.svg') }}" type="image/svg+xml">
</head>
<body>
<div class="wrap">
    <header class="brand">
        <img src="{{ asset('images/brand/logo.svg') }}" alt="TracePharma">
    </header>

    @if ($alreadyAcknowledged || session('acknowledged'))
        <p class="badge badge-success">Acknowledged</p>
        <div class="alert">Receipt of this recall notice was recorded{{ $notification->acknowledged_at ? ' on '.$notification->acknowledged_at->toDayDateTimeString() : '' }}.</div>
    @else
        <p class="badge">Recall notice</p>
    @endif

    <h1>{{ $request->title }}</h1>
    <p class="meta">For {{ $partner->name ?: 'trading partner' }}. Please confirm you received this recall broadcast.</p>

    <div class="card">
        <dl>
            @if (filled($request->gtin))
                <dt>GTIN</dt>
                <dd>{{ $request->gtin }}</dd>
            @endif
            @if (filled($request->lot))
                <dt>Lot</dt>
                <dd>{{ $request->lot }}</dd>
            @endif
            @if ($request->expiry !== null)
                <dt>Expiry</dt>
                <dd>{{ $request->expiry->toFormattedDateString() }}</dd>
            @endif
            @if (filled($request->notes))
                <dt>Notes</dt>
                <dd>{{ $request->notes }}</dd>
            @endif
        </dl>

        @unless ($alreadyAcknowledged || session('acknowledged'))
            <form method="post" action="{{ $ackSubmitUrl }}">
                @csrf
                <button type="submit" class="btn">Acknowledge receipt</button>
            </form>
        @endunless
    </div>

    <p class="footer">
        This secure link confirms your organization received the recall notice. Do not forward beyond authorized trading-partner contacts.
    </p>
</div>
</body>
</html>
