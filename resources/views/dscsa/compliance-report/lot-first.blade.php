@php
    /** @var \App\Services\Dscsa\ComplianceReport\CompliancePageData $page */
    /** @var array{generated_from: string, generated_by: string, generated_at: string} $footer */
@endphp
<div class="header">
        <div class="title">DSCSA Compliance Report ({{ $page->referenceNumber }})</div>
        <div class="shipment-id">SHIPMENT ID {{ $page->shipmentId }}</div>
    </div>

    <div class="section-title">Transaction Information</div>
    <table class="ti-table">
        <tr>
            <td class="ti-left">
                <table class="fields">
                    <tr><td class="label">PRODUCT NAME</td><td class="value">{{ $page->productName }}</td></tr>
                    <tr><td class="label">NDC</td><td class="value mono">{{ $page->ndc }}</td></tr>
                    <tr><td class="label">NUMBER OF CONTAINERS</td><td class="value">{{ number_format($page->numberOfContainers) }}</td></tr>
                    <tr><td class="label">CONTAINER SIZE</td><td class="value">{{ $page->containerSize }}</td></tr>
                    <tr><td class="label">STRENGTH</td><td class="value">{{ $page->strength }}</td></tr>
                    <tr><td class="label">DOSAGE FORM</td><td class="value">{{ $page->dosageForm }}</td></tr>
                    <tr><td class="label">QTY</td><td class="value">{{ number_format($page->qty) }}</td></tr>
                    <tr><td class="label">LOT</td><td class="value mono">{{ $page->lot }}</td></tr>
                    <tr><td class="label">EXPIRATION DATE</td><td class="value">{{ $page->expirationDate }}</td></tr>
                    <tr><td class="label">TYPE</td><td class="value">{{ $page->type }}</td></tr>
                </table>
            </td>
            <td class="ti-right">
                <table class="fields">
                    <tr><td class="label">MANUFACTURER</td><td class="value">{{ $page->manufacturer }}</td></tr>
                    <tr><td class="label">ADDRESS</td><td class="value">{{ $page->manufacturerAddress }}</td></tr>
                    <tr><td class="label">CITY</td><td class="value">{{ $page->manufacturerCity }}</td></tr>
                    <tr><td class="label">STATE</td><td class="value">{{ $page->manufacturerState }}</td></tr>
                    <tr><td class="label">ZIP</td><td class="value">{{ $page->manufacturerZip }}</td></tr>
                    <tr><td class="label">PROCESSED DATE</td><td class="value">{{ $page->processedDate }}</td></tr>
                    <tr><td class="label">TRANSACTION DATE</td><td class="value">{{ $page->transactionDate }}</td></tr>
                    <tr><td class="label">REFERENCE #</td><td class="value mono">{{ $page->referenceNumber }}</td></tr>
                    <tr><td class="label">TRACKING #</td><td class="value mono">{{ $page->trackingNumber }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title">Transaction History – Ownership Change</div>
    @if ($page->ownershipNote)
        <p class="note">{{ $page->ownershipNote }}</p>
    @endif
    <table class="history">
        <thead>
            <tr>
                <th>SENDER</th>
                <th>RECEIVER</th>
                <th>DATE</th>
                <th class="order">ORDER</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($page->ownershipRows as $row)
                <tr>
                    <td>{!! nl2br(e($row['sender'])) !!}</td>
                    <td>{!! nl2br(e($row['receiver'])) !!}</td>
                    <td>{{ $row['date'] }}</td>
                    <td class="order">{{ $row['order'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="empty">—</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">DSCSA Legal Statement</div>
    <div class="legal">
        <p>{{ $page->legalStatement }}</p>
        @if (filled($page->directPurchaseStatement))
            <p>{{ $page->directPurchaseStatement }}</p>
        @endif
    </div>

    @include('dscsa.compliance-report.partials.serials-table', [
        'serialRows' => $page->serialRows,
        'continued' => false,
    ])

    @include('dscsa.compliance-report.partials.footer', ['page' => $page, 'footer' => $footer])
