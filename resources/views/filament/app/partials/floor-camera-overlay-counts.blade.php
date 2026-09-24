@props([
    'stats' => [],
    'decode' => null,
    'error' => null,
])

@php
    $short = filled($decode)
        ? \App\Support\Gs1\ElementString::identityBarcodeDisplay((string) $decode)
        : null;
    $short = ($short === null || $short === '') && filled($decode) ? (string) $decode : $short;
@endphp

<div class="tp-floor-camera-hud" aria-live="polite">
    @if (filled($short))
        <p
            wire:key="tp-cam-decode-{{ md5($short) }}"
            class="tp-floor-camera-decode"
            x-data
            x-init="setTimeout(() => { $el.hidden = true }, 1000)"
        >{{ $short }}</p>
    @endif

    @if (filled($error))
        <p class="tp-floor-camera-error" role="alert">{{ $error }}</p>
    @endif

    <p
        x-show="cameraError"
        x-cloak
        x-text="cameraError"
        class="tp-floor-camera-error"
        role="alert"
    ></p>

    <div class="tp-floor-camera-counts">
        @foreach ($stats as $stat)
            <span class="tp-floor-camera-counts__stat">
                <span class="tp-floor-camera-counts__value">{{ $stat['value'] }}</span>
                <span class="tp-floor-camera-counts__label">{{ $stat['title'] }}</span>
            </span>
        @endforeach
    </div>
</div>
