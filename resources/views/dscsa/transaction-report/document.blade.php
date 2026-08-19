<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Transaction Report ({{ $report->referenceNumber }})</title>
    <style>
        @page {
            margin: 0.5in;
            size: letter portrait;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
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
            margin-bottom: 12px;
        }

        .title {
            font-family: DejaVu Serif, serif;
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 0.2px;
        }

        .shipment-id {
            margin-top: 4px;
            font-size: 9pt;
            color: #333;
        }

        .section-title {
            font-weight: bold;
            font-size: 11pt;
            margin: 14px 0 6px;
            border-bottom: 1px solid #444;
            padding-bottom: 3px;
        }

        .ti-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #333;
        }

        .ti-left,
        .ti-right {
            width: 50%;
            vertical-align: top;
            padding: 8px 10px;
            border: 1px solid #333;
        }

        .fields {
            width: 100%;
            border-collapse: collapse;
        }

        .fields td {
            padding: 2px 0;
            vertical-align: top;
        }

        .fields .label {
            width: 42%;
            color: #333;
            font-weight: bold;
            padding-right: 8px;
        }

        .fields .value {
            width: 58%;
        }

        .mono {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 9pt;
        }

        .history {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #333;
        }

        .history th,
        .history td {
            border: 1px solid #333;
            padding: 6px 8px;
            vertical-align: top;
            text-align: left;
        }

        .history th {
            background: #f0f0f0;
            font-size: 9pt;
        }

        .history .order {
            width: 48px;
            text-align: center;
        }

        .history .empty {
            text-align: center;
            color: #666;
        }

        .note {
            font-size: 9pt;
            font-style: italic;
            color: #444;
            margin: 0 0 6px;
        }

        .legal {
            border: 1px solid #333;
            padding: 10px 12px;
            font-size: 9pt;
            line-height: 1.35;
        }

        .legal p {
            margin: 0 0 8px;
        }

        .legal p:last-child {
            margin-bottom: 0;
        }

        .footer {
            margin-top: 18px;
            border-top: 1px solid #666;
            padding-top: 6px;
            font-size: 8pt;
            color: #333;
            line-height: 1.4;
        }
    </style>
</head>
<body>
@foreach ($report->pages as $page)
    <div class="page{{ $loop->first ? '' : ' page-break-before' }}">
        @include('dscsa.transaction-report.lot-page', ['page' => $page, 'footer' => $report->footer])
    </div>
@endforeach
</body>
</html>
