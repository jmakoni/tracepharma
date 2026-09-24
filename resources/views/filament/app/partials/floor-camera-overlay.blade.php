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
    @keydown.escape.window="if (scanSettingsOpen) { scanSettingsOpen = false }"
>
    <p class="tp-floor-receive__camera-title">Align barcode</p>

    <div class="tp-floor-receive__camera-stage">
        <div wire:ignore class="tp-floor-receive__camera-host">
            <div id="tp-floor-qr-reader" class="tp-floor-receive__camera"></div>
        </div>

        <div class="tp-floor-camera-dock">
            @include('filament.app.partials.floor-camera-overlay-counts', [
                'stats' => $stats,
                'decode' => $decode,
                'error' => $error,
            ])
        </div>
    </div>

    <div class="tp-floor-camera-chrome" role="toolbar" aria-label="Camera controls">
        <button
            type="button"
            class="tp-floor-camera-chrome__btn"
            :class="{ 'is-torch-on': torchOn, 'is-disabled': ! torchSupported }"
            x-bind:disabled="! torchSupported || starting"
            x-on:click="toggleTorch()"
            :aria-pressed="torchOn ? 'true' : 'false'"
            aria-label="Torch"
        >
            <x-filament::icon icon="heroicon-o-bolt" class="tp-floor-camera-chrome__icon" />
        </button>

        <div class="tp-floor-camera-chrome__gear">
            <button
                type="button"
                class="tp-floor-camera-chrome__btn"
                :class="{ 'is-open': scanSettingsOpen }"
                x-on:click="scanSettingsOpen = ! scanSettingsOpen"
                :aria-expanded="scanSettingsOpen ? 'true' : 'false'"
                aria-haspopup="true"
                aria-label="Scan settings"
            >
                <x-filament::icon icon="heroicon-o-cog-6-tooth" class="tp-floor-camera-chrome__icon" />
            </button>

            <div
                x-show="scanSettingsOpen"
                x-cloak
                x-transition.opacity.duration.150ms
                class="tp-floor-camera-chrome__sheet menu bg-base-100 rounded-box shadow-lg z-20"
                role="dialog"
                aria-label="Camera settings"
                @click.outside="scanSettingsOpen = false"
            >
                <div class="tp-floor-camera-chrome__sheet-group" role="group" aria-label="Scan mode">
                    <p class="tp-floor-camera-chrome__sheet-title">Scan mode</p>
                    <button
                        type="button"
                        role="menuitemradio"
                        class="tp-floor-camera-chrome__sheet-option"
                        :class="{ 'is-active': scanMode === 'single' }"
                        :aria-checked="scanMode === 'single' ? 'true' : 'false'"
                        x-on:click="setScanMode('single')"
                    >
                        Single
                    </button>
                    <button
                        type="button"
                        role="menuitemradio"
                        class="tp-floor-camera-chrome__sheet-option"
                        :class="{ 'is-active': scanMode === 'continuous' }"
                        :aria-checked="scanMode === 'continuous' ? 'true' : 'false'"
                        x-on:click="setScanMode('continuous')"
                    >
                        Continuous
                    </button>
                </div>

                <div class="tp-floor-camera-chrome__sheet-group" role="group" aria-label="Confidence">
                    <p class="tp-floor-camera-chrome__sheet-title">Confidence</p>
                    <button
                        type="button"
                        role="menuitemradio"
                        class="tp-floor-camera-chrome__sheet-option"
                        :class="{ 'is-active': confidence === 'careful' }"
                        :aria-checked="confidence === 'careful' ? 'true' : 'false'"
                        x-on:click="setConfidence('careful')"
                    >
                        Careful
                    </button>
                    <button
                        type="button"
                        role="menuitemradio"
                        class="tp-floor-camera-chrome__sheet-option"
                        :class="{ 'is-active': confidence === 'balanced' }"
                        :aria-checked="confidence === 'balanced' ? 'true' : 'false'"
                        x-on:click="setConfidence('balanced')"
                    >
                        Balanced
                    </button>
                    <button
                        type="button"
                        role="menuitemradio"
                        class="tp-floor-camera-chrome__sheet-option"
                        :class="{ 'is-active': confidence === 'rapid' }"
                        :aria-checked="confidence === 'rapid' ? 'true' : 'false'"
                        x-on:click="setConfidence('rapid')"
                    >
                        Rapid
                    </button>
                </div>
            </div>
        </div>

        <button
            type="button"
            class="tp-floor-camera-chrome__btn"
            x-ref="cameraClose"
            x-on:click="stopCamera()"
            aria-label="Close camera"
        >
            <x-filament::icon icon="heroicon-o-x-mark" class="tp-floor-camera-chrome__icon" />
        </button>
    </div>
</div>
