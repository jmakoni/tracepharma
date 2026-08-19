@php
    use App\Models\Epcis\EpcisDocument;

    /** @var \App\Models\Epcis\EpcisDocument|null $record */
    $record = $record ?? null;
    if (! $record instanceof EpcisDocument) {
        return;
    }

    $summary = $record->fileShipmentSummary();
    $productCount = (int) $summary['product_count'];
    $lotCount = (int) $summary['lot_count'];
    $itemCount = (int) $summary['item_count'];
    $productNdcs = $summary['product_ndcs'];
    $lots = $summary['lots'];
    $caseUnitLabel = $summary['case_unit_label'];
@endphp

<div class="tp-epcis-document-summary">
    <div class="tp-epcis-document-summary__grid">
        <div class="tp-epcis-document-summary__entry">
            <div class="tp-epcis-document-summary__label">Products</div>
            <div class="tp-epcis-document-summary__metric">{{ number_format($productCount) }}</div>
            @if ($productNdcs !== [])
                <div class="tp-epcis-document-summary__chips">
                    @foreach ($productNdcs as $ndc)
                        <span class="tp-epcis-document-summary__chip">{{ $ndc }}</span>
                    @endforeach
                </div>
            @else
                <p class="tp-epcis-document-summary__empty">No product NDCs in file</p>
            @endif
        </div>

        <div class="tp-epcis-document-summary__entry">
            <div class="tp-epcis-document-summary__label">Lots</div>
            <div class="tp-epcis-document-summary__metric">{{ number_format($lotCount) }}</div>
            @if ($lots !== [])
                <div class="tp-epcis-document-summary__chips">
                    @foreach ($lots as $lot)
                        <span class="tp-epcis-document-summary__chip">{{ $lot }}</span>
                    @endforeach
                </div>
            @else
                <p class="tp-epcis-document-summary__empty">No lot numbers in file</p>
            @endif
        </div>

        <div class="tp-epcis-document-summary__entry">
            <div class="tp-epcis-document-summary__label">Items</div>
            <div class="tp-epcis-document-summary__metric">{{ number_format($itemCount) }}</div>
            <div class="tp-epcis-document-summary__items-detail">
                <p class="tp-epcis-document-summary__subline">{{ $caseUnitLabel }}</p>
                <p class="tp-epcis-document-summary__hint">Total EPCs in file (includes SSCC)</p>
            </div>
        </div>
    </div>
</div>
