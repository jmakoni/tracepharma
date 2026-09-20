@php
    $paceOptions = \App\Enums\FloorCameraScanPace::ordered();
@endphp

<div
    class="tp-floor-receive__camera-pace"
    role="group"
    aria-label="Camera scan pace"
>
    @foreach ($paceOptions as $paceOption)
        <button
            type="button"
            class="tp-floor-receive__camera-pace-btn"
            :class="{ 'is-active': cameraScanPace === @js($paceOption->value) }"
            :aria-pressed="cameraScanPace === @js($paceOption->value) ? 'true' : 'false'"
            x-on:click="setScanPace(@js($paceOption->value))"
            x-bind:disabled="starting"
        >
            <x-filament::icon
                :icon="$paceOption->icon()"
                class="tp-floor-receive__camera-pace-icon"
            />
            <span>{{ $paceOption->label() }}</span>
        </button>
    @endforeach
</div>
