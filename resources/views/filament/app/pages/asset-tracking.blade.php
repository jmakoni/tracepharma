<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
        <style>
            .tp-journey-marker {
                background: transparent;
                border: 0;
            }
            .tp-journey-marker__seq {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                border-radius: 9999px;
                background: #51BC8F;
                color: #fff;
                font-size: 12px;
                font-weight: 700;
                box-shadow: 0 1px 4px rgb(0 0 0 / 0.35);
            }
        </style>
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script src="{{ asset('js/tp-asset-tracking-map.js') }}"></script>
    @endassets

    {{ $this->content }}
</x-filament-panels::page>
