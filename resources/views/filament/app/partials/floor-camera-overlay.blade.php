@props([
    'stats' => [],
    'decode' => null,
    'error' => null,
])

<div
    x-show="cameraOn"
    x-cloak
    class="tp-floor-receive__camera-overlay"
    role="dialog"
    aria-modal="true"
    aria-label="Camera scanner"
    @keydown="trapTab($event, $el)"
>
    <p class="tp-floor-receive__camera-title">Align barcode</p>

    <div class="tp-floor-receive__camera-stage">
        <div wire:ignore class="tp-floor-receive__camera-host">
            <div id="tp-floor-qr-reader" class="tp-floor-receive__camera"></div>
        </div>
    </div>

    <div class="tp-floor-camera-dock">
        @include('filament.app.partials.floor-camera-overlay-counts', [
            'stats' => $stats,
            'decode' => $decode,
            'error' => $error,
        ])

        <div class="tp-floor-receive__camera-controls">
            @include('filament.app.partials.floor-camera-scan-pace')
            <button
                type="button"
                class="tp-floor-receive__camera-close"
                x-show="torchSupported"
                x-cloak
                x-on:click="toggleTorch()"
                :aria-pressed="torchOn ? 'true' : 'false'"
                :aria-label="torchOn ? 'Turn torch off' : 'Turn torch on'"
            >
                <span x-text="torchOn ? 'Torch on' : 'Torch'"></span>
            </button>
            <button
                type="button"
                class="tp-floor-receive__camera-close"
                x-ref="cameraClose"
                x-on:click="stopCamera()"
            >
                Close
            </button>
        </div>
    </div>
</div>
