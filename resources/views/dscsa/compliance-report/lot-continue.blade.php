@php
    /** @var \App\Services\Dscsa\ComplianceReport\CompliancePageData $page */
    /** @var array{generated_from: string, generated_by: string, generated_at: string} $footer */
@endphp
<div class="header compact">
        <div class="title">DSCSA Compliance Report ({{ $page->referenceNumber }})</div>
        <div class="shipment-id">SHIPMENT ID {{ $page->shipmentId }} · LOT {{ $page->lot }}</div>
    </div>

    @include('dscsa.compliance-report.partials.serials-table', [
        'serialRows' => $page->serialRows,
        'continued' => true,
    ])

    @include('dscsa.compliance-report.partials.footer', ['page' => $page, 'footer' => $footer])
