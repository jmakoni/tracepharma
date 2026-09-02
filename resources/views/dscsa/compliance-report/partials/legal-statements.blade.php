@php
    /** @var string $legalStatement */
    /** @var ?string $directPurchaseStatement */
    /** @var ?string $receivedPrevWholesalerStatement */
@endphp
<div class="section-title">DSCSA Legal Statement</div>
<div class="legal">
    <p>{{ $legalStatement }}</p>
    @if (filled($directPurchaseStatement))
        <p>{{ $directPurchaseStatement }}</p>
    @endif
    @if (filled($receivedPrevWholesalerStatement))
        <p>{{ $receivedPrevWholesalerStatement }}</p>
    @endif
</div>
