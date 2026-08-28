<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Supplier Quarantine · Case #{{ $case->id }}</title>
    <style>
        :root {
            --ink: #1c2430;
            --muted: #5b6573;
            --line: #d8dee6;
            --bg: #f4f6f8;
            --card: #ffffff;
            --danger: #9b1c1c;
            --accent: #0f4c5c;
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
            background: #fde8e8;
            color: var(--danger);
            font-size: 0.8rem;
            font-weight: 600;
        }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; min-width: 640px; }
        th, td { text-align: left; padding: 0.55rem 0.45rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; word-break: break-all; }
        button.copy-id {
            background: transparent;
            color: var(--muted);
            border: 0;
            border-radius: 0.25rem;
            padding: 0 0.2rem;
            margin-left: 0.2rem;
            font: inherit;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            vertical-align: middle;
        }
        button.copy-id:hover { color: var(--accent); }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
        input, textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 0.4rem;
            padding: 0.65rem 0.75rem;
            font: inherit;
            margin-bottom: 0.85rem;
            background: #fff;
        }
        button {
            background: var(--accent);
            color: #fff;
            border: 0;
            border-radius: 0.4rem;
            padding: 0.7rem 1.1rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        .flash { background: #e7f6ef; border: 1px solid #b7e0cb; color: #14532d; padding: 0.75rem 1rem; border-radius: 0.4rem; margin-bottom: 1rem; }
        .banner {
            background: #fde8e8;
            border: 1px solid #f5c2c2;
            color: var(--danger);
            padding: 0.9rem 1.1rem;
            border-radius: 0.4rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }
        .banner p { margin: 0.35rem 0 0; font-weight: 400; color: var(--ink); }
        .activity { border-left: 3px solid var(--line); padding-left: 0.85rem; margin-bottom: 0.85rem; }
        .activity time { color: var(--muted); font-size: 0.8rem; }
        .footer { color: var(--muted); font-size: 0.85rem; margin-top: 1.5rem; }
        .status-open { color: var(--accent); font-weight: 600; }
        .status-done { color: #14532d; font-weight: 600; }
        .pager {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem 1rem;
            margin-top: 0.85rem;
            font-size: 0.9rem;
        }
        .pager .meta { margin: 0; }
        .pager-links { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
        .pager-links a, .pager-links span {
            display: inline-block;
            padding: 0.3rem 0.55rem;
            border: 1px solid var(--line);
            border-radius: 0.35rem;
            text-decoration: none;
            color: var(--ink);
            background: #fff;
        }
        .pager-links a:hover { border-color: var(--accent); color: var(--accent); }
        .pager-links .current {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            font-weight: 600;
        }
        .pager-links .disabled { color: var(--muted); background: #f4f6f8; }
        .per-page { display: flex; align-items: center; gap: 0.4rem; color: var(--muted); }
        .per-page a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .per-page a.active { text-decoration: underline; }
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
    <p class="badge">{{ $openHoldCount }} unit(s) quarantined</p>
    <h1>{{ $case->title }}</h1>
    <p class="meta">
        Case #{{ $case->id }}
        · {{ $case->type?->name ?? 'Exception' }}
        · {{ $case->tradingPartner?->name ?? 'Trading partner' }}
        · Available until this exception is resolved
        @if (isset($signalGroups) && $signalGroups->isNotEmpty())
            @php
                $countLabels = $signalGroups
                    ->pluck('gtin_display')
                    ->filter(fn ($label) => filled($label) && $label !== '—')
                    ->unique()
                    ->values();
            @endphp
            @if ($countLabels->isNotEmpty())
                · {{ $countLabels->implode(' · ') }}
            @endif
        @endif
    </p>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    @if (! empty($documentScoped))
        <div class="banner">
            Entire shipment file is affected
            <p>
                All products in this EPCIS file
                @if (! empty($shipmentRef))
                    (PO/ASN {{ $shipmentRef }})
                @endif
                cannot be received until this exception is resolved.
                Identifiers list one row per case serial; quantity is the child unit count in that case.
            </p>
        </div>
    @endif

    <div class="card">
        <h2 style="margin:0 0 0.5rem;font-size:1.05rem;">Reason</h2>
        <p style="margin:0;white-space:pre-wrap;">{{ $case->description ?: 'No description provided.' }}</p>
    </div>

    <div class="card">
        <h2 style="margin:0 0 0.75rem;font-size:1.05rem;">Affected products</h2>
        @if ($summaryRows->isEmpty())
            <p class="meta" style="margin:0;">No products listed on this case.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>PO</th>
                        <th>NDC</th>
                        <th>Item / product name</th>
                        <th class="num">Days held</th>
                        <th class="num">Quantity</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($summaryRows as $row)
                        <tr>
                            <td><x-copyable-plain :value="$row['po'] ?? null" title="Copy PO" /></td>
                            <td><x-copyable-plain :value="$row['ndc'] ?? null" title="Copy NDC" /></td>
                            <td>{{ $row['product_name'] }}</td>
                            <td class="num">{{ $row['days_held'] }}</td>
                            <td class="num">{{ $row['quantity'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2 style="margin:0 0 0.75rem;font-size:1.05rem;">Affected identifiers</h2>
        @if ($identifierTotal === 0)
            <p class="meta" style="margin:0;">No serials listed on this case.</p>
        @else
            <div class="pager" style="margin-top:0;margin-bottom:0.75rem;">
                <p class="meta">
                    Showing {{ $identifierRows->firstItem() }}–{{ $identifierRows->lastItem() }}
                    of {{ $identifierTotal }}
                </p>
                <div class="per-page">
                    <span>Rows:</span>
                    @foreach ($perPageOptions as $option)
                        @php
                            $perPageUrl = request()->fullUrlWithQuery(['per_page' => $option, 'page' => 1]);
                        @endphp
                        <a href="{{ $perPageUrl }}" class="{{ (int) $perPage === (int) $option ? 'active' : '' }}">{{ $option }}</a>
                    @endforeach
                </div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>PO</th>
                        <th>NDC</th>
                        <th>Item / product name</th>
                        <th>GTIN</th>
                        <th>Serial #</th>
                        <th>Lot #</th>
                        <th>Exp</th>
                        <th class="num">Quantity</th>
                        <th>Status</th>
                        <th>Date quarantined</th>
                        <th>Date resolved</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($identifierRows as $row)
                        <tr>
                            <td><x-copyable-plain :value="$row['po'] ?? null" title="Copy PO" /></td>
                            <td><x-copyable-plain :value="$row['ndc'] ?? null" title="Copy NDC" /></td>
                            <td>{{ $row['product_name'] }}</td>
                            <td><x-copyable-plain :value="$row['gtin'] ?? null" title="Copy GTIN" /></td>
                            <td><x-copyable-plain :value="$row['serial'] ?? null" title="Copy serial" /></td>
                            <td><x-copyable-plain :value="$row['lot'] ?? null" title="Copy lot" /></td>
                            <td class="mono">{{ $row['exp'] }}</td>
                            <td class="num">{{ $row['quantity'] }}</td>
                            <td class="{{ in_array($row['status'], ['Resolved', 'Closed', 'Cancelled'], true) ? 'status-done' : 'status-open' }}">
                                {{ $row['status'] }}
                            </td>
                            <td>{{ $row['date_quarantined'] ?? '—' }}</td>
                            <td>{{ $row['date_resolved'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($identifierRows->hasPages())
                <div class="pager">
                    <p class="meta">Page {{ $identifierRows->currentPage() }} of {{ $identifierRows->lastPage() }}</p>
                    <div class="pager-links">
                        @if ($identifierRows->onFirstPage())
                            <span class="disabled">Prev</span>
                        @else
                            <a href="{{ $identifierRows->previousPageUrl() }}">Prev</a>
                        @endif

                        @foreach ($identifierRows->getUrlRange(max(1, $identifierRows->currentPage() - 2), min($identifierRows->lastPage(), $identifierRows->currentPage() + 2)) as $page => $url)
                            @if ($page === $identifierRows->currentPage())
                                <span class="current">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}">{{ $page }}</a>
                            @endif
                        @endforeach

                        @if ($identifierRows->hasMorePages())
                            <a href="{{ $identifierRows->nextPageUrl() }}">Next</a>
                        @else
                            <span class="disabled">Next</span>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </div>

    @if (! empty($canUploadCorrectedEpcis) && ! empty($uploadUrl))
        <div class="card">
            <h2 style="margin:0 0 0.75rem;font-size:1.05rem;">Upload corrected EPCIS</h2>
            <p class="meta">Submit a replacement EPCIS 1.2 or 1.3 XML file. EPCIS 1.0 is not accepted.</p>
            <form method="post" action="{{ $uploadUrl }}" enctype="multipart/form-data">
                @csrf
                <label for="file">EPCIS 1.2 / 1.3 XML</label>
                <input id="file" name="file" type="file" accept=".xml,text/xml,application/xml" required>
                @error('file')
                    <p style="color:var(--danger);margin-top:-0.5rem;">{{ $message }}</p>
                @enderror
                <button type="submit">Upload corrected EPCIS</button>
            </form>
        </div>
    @endif

    <div class="card">
        <h2 style="margin:0 0 0.75rem;font-size:1.05rem;">Apply correction</h2>
        <p class="meta">Acknowledge the exception and optionally provide corrected shipment / product fields. Your buyer remains the authority to resolve the case.</p>
        <form method="post" action="{{ $applyUrl }}">
            @csrf
            <label for="apply_supplier_name">Your name / company</label>
            <input id="apply_supplier_name" name="supplier_name" value="{{ old('supplier_name') }}" maxlength="150">

            <label for="corrected_reference">Corrected shipment / document reference</label>
            <input id="corrected_reference" name="corrected_reference" value="{{ old('corrected_reference') }}" maxlength="255">

            <label for="gtin">Corrected GTIN</label>
            <input id="gtin" name="gtin" value="{{ old('gtin') }}" maxlength="64" class="mono">

            <label for="serial">Corrected serial</label>
            <input id="serial" name="serial" value="{{ old('serial') }}" maxlength="128" class="mono">

            <label for="lot">Corrected lot</label>
            <input id="lot" name="lot" value="{{ old('lot') }}" maxlength="128" class="mono">

            <label for="expiry">Corrected expiry</label>
            <input id="expiry" name="expiry" value="{{ old('expiry') }}" maxlength="64" placeholder="YYYY-MM-DD">

            <label for="apply_notes">Notes</label>
            <textarea id="apply_notes" name="notes" rows="3" maxlength="5000">{{ old('notes') }}</textarea>

            <label style="display:flex;align-items:flex-start;gap:0.5rem;font-weight:600;margin-bottom:0.85rem;">
                <input type="checkbox" name="acknowledged" value="1" style="width:auto;margin:0.2rem 0 0;" @checked(old('acknowledged')) required>
                <span>I acknowledge this exception and the correction details above</span>
            </label>
            @error('acknowledged')
                <p style="color:var(--danger);margin-top:-0.5rem;">{{ $message }}</p>
            @enderror

            <button type="submit">Submit correction</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 0.75rem;font-size:1.05rem;">Supplier response</h2>
        <form method="post" action="{{ $commentUrl }}">
            @csrf
            <label for="supplier_name">Your name / company</label>
            <input id="supplier_name" name="supplier_name" value="{{ old('supplier_name') }}" maxlength="150">

            <label for="body">Comment / acknowledgment</label>
            <textarea id="body" name="body" rows="4" required maxlength="5000">{{ old('body') }}</textarea>
            @error('body')
                <p style="color:var(--danger);margin-top:-0.5rem;">{{ $message }}</p>
            @enderror

            <button type="submit">Submit response</button>
        </form>
    </div>

    @if ($partnerActivities->isNotEmpty())
        <div class="card">
            <h2 style="margin:0 0 0.75rem;font-size:1.05rem;">Conversation</h2>
            @foreach ($partnerActivities as $activity)
                <div class="activity">
                    <div>{{ $activity->body }}</div>
                    <time>{{ $activity->created_at?->toDayDateTimeString() }}</time>
                </div>
            @endforeach
        </div>
    @endif

    <p class="footer">
        This page is a secure, time-limited supplier collaboration view for a DSCSA quarantine / suspect-product investigation.
        Do not forward beyond authorized trading-partner contacts.
    </p>
</div>
</body>
</html>
