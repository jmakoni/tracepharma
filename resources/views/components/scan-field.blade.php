@props([
    'wireModel' => 'scan',
    'inputId' => 'floor-scan-input',
    'readerId' => 'tp-floor-qr-reader',
    'label' => 'Scan barcode',
    'placeholder' => 'Scan or type barcode',
    'confirmLabel' => 'Confirm',
    'showCamera' => false,
    'submitAction' => 'confirmScan',
    'variant' => 'floor',
])

@php
    $isDesktop = $variant === 'desktop';
    $inputClass = $isDesktop
        ? 'tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500'
        : 'tp-scan-input tp-scan-input--macro min-h-14 min-w-0 flex-1 rounded-lg border border-base-300 bg-base-100 px-3 py-2 font-mono text-lg shadow-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20';
    $confirmBtnClass = $isDesktop
        ? 'btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4'
        : 'tp-scanner-macro-btn btn btn-primary min-h-14 w-auto shrink-0 px-4';
    $cameraBtnClass = $isDesktop
        ? 'btn btn-outline min-h-14'
        : 'tp-scanner-macro-btn tp-scanner-macro-btn--neutral btn btn-outline min-h-14';
@endphp

<div
    class="space-y-3"
    x-data="{
        cameraOn: false,
        scanner: null,
        cameraError: null,
        libraryUrl: @js(asset('vendor/html5-qrcode/html5-qrcode.min.js')),
        async toggleCamera() {
            if (this.cameraOn) {
                await this.stopCamera();
                return;
            }
            this.cameraError = null;
            try {
                if (! window.Html5Qrcode) {
                    await new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = this.libraryUrl;
                        s.onload = resolve;
                        s.onerror = () => reject(new Error('Camera library failed to load'));
                        document.head.appendChild(s);
                    });
                }
                const elId = @js($readerId);
                this.scanner = new Html5Qrcode(elId);
                await this.scanner.start(
                    { facingMode: 'environment' },
                    { fps: 8, qrbox: { width: 240, height: 240 } },
                    async (decoded) => {
                        if (! decoded) return;
                        await $wire.set(@js($wireModel), decoded);
                        $wire.mountAction(@js($submitAction));
                    },
                    () => {},
                );
                this.cameraOn = true;
            } catch (e) {
                this.cameraError = e?.message || 'Camera unavailable';
                this.cameraOn = false;
                this.scanner = null;
            }
        },
        async stopCamera() {
            try {
                if (this.scanner) {
                    await this.scanner.stop();
                    await this.scanner.clear();
                }
            } catch (e) {}
            this.scanner = null;
            this.cameraOn = false;
        },
    }"
    x-on:destroy="stopCamera()"
>
    <form
        wire:submit.prevent="mountAction('{{ $submitAction }}')"
        x-init="$nextTick(() => $refs.scanInput?.focus())"
        x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
        class="flex flex-col gap-3"
    >
        <div class="form-control w-full gap-1.5">
            <label for="{{ $inputId }}" class="label-text text-sm font-medium">{{ $label }}</label>
            <div class="flex w-full items-stretch gap-2">
                <input
                    id="{{ $inputId }}"
                    type="text"
                    wire:model="{{ $wireModel }}"
                    x-ref="scanInput"
                    autocomplete="off"
                    autofocus
                    class="{{ $inputClass }}"
                    placeholder="{{ $placeholder }}"
                />
                <button
                    type="submit"
                    class="{{ $confirmBtnClass }}"
                    wire:loading.attr="disabled"
                >
                    {{ $confirmLabel }}
                </button>
            </div>
        </div>

        @if ($showCamera)
            <div class="flex flex-col gap-2">
                <button
                    type="button"
                    class="{{ $cameraBtnClass }}"
                    x-on:click="toggleCamera()"
                >
                    <span x-text="cameraOn ? 'Stop camera' : 'Camera'"></span>
                </button>
                <p x-show="cameraError" x-cloak x-text="cameraError" class="text-sm text-error" role="alert"></p>
                <div id="{{ $readerId }}" class="max-w-md overflow-hidden rounded-lg border border-base-300" x-show="cameraOn" x-cloak wire:ignore></div>
            </div>
        @endif
    </form>
</div>
