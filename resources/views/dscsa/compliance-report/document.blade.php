<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>DSCSA Compliance Report ({{ $report->referenceNumber }})</title>
    <style>
        @page {
            margin: 0.5in;
            size: letter portrait;
        }

        * { box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5pt;
            color: #111;
            margin: 0;
            padding: 0;
        }

        .page {
            position: relative;
            padding-bottom: 12px;
        }

        /* Dompdf: prefer break-before on subsequent pages; break-after often inserts blanks. */
        .page-break-before {
            page-break-before: always;
        }

        .header {
            border-bottom: 2px solid #222;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }

        .header.compact { margin-bottom: 12px; }

        .title {
            font-family: DejaVu Serif, serif;
            font-size: 15pt;
            font-weight: bold;
        }

        .shipment-id {
            margin-top: 4px;
            font-size: 9pt;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .section-title {
            font-weight: bold;
            font-size: 10.5pt;
            margin: 12px 0 5px;
            border-bottom: 1px solid #444;
            padding-bottom: 2px;
        }

        .section-title .continued {
            font-weight: normal;
            font-size: 9pt;
            color: #555;
        }

        .ti-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #333;
        }

        .ti-left, .ti-right {
            width: 50%;
            vertical-align: top;
            padding: 7px 9px;
            border: 1px solid #333;
        }

        .fields { width: 100%; border-collapse: collapse; }
        .fields td { padding: 1px 0; vertical-align: top; }
        .fields .label {
            width: 48%;
            color: #222;
            font-weight: bold;
            font-size: 8pt;
            padding-right: 6px;
        }
        .fields .value { width: 52%; font-size: 9pt; }

        .mono {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8.5pt;
        }

        .history, .serials {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #333;
        }

        .history th, .history td,
        .serials th, .serials td {
            border: 1px solid #333;
            padding: 4px 6px;
            vertical-align: top;
            text-align: left;
            font-size: 8.5pt;
        }

        .history th, .serials th {
            background: #f0f0f0;
            font-size: 8pt;
        }

        .history .order { width: 48px; text-align: center; }
        .empty { text-align: center; color: #666; }

        .note {
            font-size: 8.5pt;
            font-style: italic;
            color: #444;
            margin: 0 0 5px;
        }

        .legal {
            border: 1px solid #333;
            padding: 8px 10px;
            font-size: 8.5pt;
            line-height: 1.35;
        }

        .legal p { margin: 0 0 6px; }
        .legal p:last-child { margin-bottom: 0; }

        .footer {
            margin-top: 16px;
            border-top: 1px solid #666;
            padding-top: 5px;
            font-size: 7.5pt;
            color: #333;
            line-height: 1.35;
        }

        .footer .page-num {
            margin-top: 2px;
            font-weight: bold;
        }
    </style>
</head>
<body>
@foreach ($report->pages as $page)
    <div class="page{{ $loop->first ? '' : ' page-break-before' }}">
        @if ($page->kind === 'lot_continue')
            @include('dscsa.compliance-report.lot-continue', ['page' => $page, 'footer' => $report->footer])
        @else
            @include('dscsa.compliance-report.lot-first', ['page' => $page, 'footer' => $report->footer])
        @endif
    </div>
@endforeach
</body>
</html>
