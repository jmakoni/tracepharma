@php
    /** @var string $title */
    /** @var string $subtitle */
    /** @var ?string $logoDataUri */
    /** @var bool $compact */
    $compact = $compact ?? false;
@endphp
<div class="header{{ $compact ? ' compact' : '' }}">
    <div class="header-row">
        @if (! $compact && filled($logoDataUri ?? null))
            <div class="header-brand">
                <img src="{{ $logoDataUri }}" alt="" class="header-logo">
            </div>
        @endif
        <div class="header-title">
            <div class="title">{{ $title }}</div>
            <div class="shipment-id">{{ $subtitle }}</div>
        </div>
    </div>
</div>
