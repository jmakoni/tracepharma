<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 12pt;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #111111;
            margin: 0;
        }

        .label {
            width: 100%;
        }

        .header {
            border-bottom: 1px solid #cccccc;
            margin-bottom: 10pt;
            padding-bottom: 6pt;
        }

        .title {
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .meta {
            margin-top: 4pt;
            font-size: 9pt;
            line-height: 1.35;
        }

        .barcode-wrap {
            text-align: center;
            margin: 14pt 0 8pt;
        }

        .barcode-wrap img {
            width: 100%;
            max-height: 72pt;
        }

        .hrt {
            text-align: center;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.04em;
            margin-bottom: 10pt;
        }

        .details {
            font-size: 9pt;
            line-height: 1.45;
        }

        .details dt {
            font-weight: bold;
            display: inline;
        }

        .details dd {
            display: inline;
            margin: 0 0 6pt;
        }

        .notes {
            margin-top: 10pt;
            font-size: 8.5pt;
            color: #444444;
        }

        .footer {
            margin-top: 12pt;
            font-size: 7.5pt;
            color: #666666;
            border-top: 1px solid #dddddd;
            padding-top: 6pt;
        }
    </style>
</head>
<body>
    <div class="label">
        <div class="header">
            <div class="title">Pallet / Shipper Label (SSCC)</div>
            <div class="meta">
                @if ($shipFromName)
                    <div><strong>Ship from:</strong> {{ $shipFromName }}@if ($shipFromGln && $shipFromGln !== '—') (GLN {{ $shipFromGln }})@endif</div>
                @endif
                @if ($shipToName || $shipToGln)
                    <div><strong>Ship to:</strong> {{ $shipToName ?? '—' }}@if ($shipToGln && $shipToGln !== '—') (GLN {{ $shipToGln }})@endif</div>
                @endif
            </div>
        </div>

        <div class="barcode-wrap">
            <img src="{{ $barcodeDataUri }}" alt="GS1-128 SSCC barcode">
        </div>

        <div class="hrt">{{ $hrt }}</div>

        <dl class="details">
            <div><dt>SSCC-18:</dt> <dd>{{ $sscc18 }}</dd></div>
            <div><dt>EPC URI:</dt> <dd>{{ $ssccUrn }}</dd></div>
        </dl>

        @if ($notes)
            <div class="notes"><strong>Notes:</strong> {{ $notes }}</div>
        @endif

        <div class="footer">
            Generated {{ $generatedAt }} · GS1 Application Identifier (00) · Element string matches barcode data.
        </div>
    </div>
</body>
</html>
