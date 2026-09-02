@php
    /** @var \App\Services\Dscsa\ComplianceReport\CompliancePageData $page */
    /** @var array{generated_from: string, generated_by: string, generated_at: string} $footer */
@endphp
@include('dscsa.compliance-report.partials.header', [
        'title' => 'DSCSA Compliance Report ('.$page->referenceNumber.')',
        'subtitle' => 'SHIPMENT ID '.$page->shipmentId.' · LOT '.$page->lot,
        'logoDataUri' => null,
        'compact' => true,
    ])

    @include('dscsa.compliance-report.partials.serials-table', [
        'serialRows' => $page->serialRows,
        'continued' => true,
    ])

    @include('dscsa.compliance-report.partials.footer', ['page' => $page, 'footer' => $footer])
