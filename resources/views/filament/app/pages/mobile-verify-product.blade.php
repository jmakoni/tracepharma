<x-filament-panels::page>
    @assets
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}" data-tp-html5-qrcode="1"></script>
        <script src="{{ asset('js/tp-floor-receive.js') }}?v={{ @filemtime(public_path('js/tp-floor-receive.js')) ?: time() }}"></script>
    @endassets

    <x-scan-flash />

    <div class="tp-floor-receive__layout-switch">
        @include('filament.app.partials.floor-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopVerifyUrl(),
            'floorUrl' => $this->floorVerifyUrl(),
        ])
    </div>

    @php
        $verificationRows = $this->verificationTableRows()
            ->map(fn (array $row): array => [
                'id' => (int) $row['line_id'],
                'label' => (string) $row['identifier'],
                'type' => (string) $row['status_label'],
                'can_remove' => false,
            ])
            ->values()
            ->all();
        $todayCount = count($verificationRows);
    @endphp

    <div
        class="tp-floor-receive tp-floor-verify"
        x-data="tpFloorReceive(@js(\App\Support\Floor\FloorCameraScanAlpine::tpFloorReceiveConfig('verifyScan')))"
        x-on:destroy="stopCamera()"
        @keydown.escape.window="if (cameraOn) { stopCamera() }"
        x-on:focus-scan.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
        x-on:close-modal.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
        x-on:modal-closed.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
    >
        <header class="tp-floor-receive__sticky-header">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="tp-floor-receive__header-top">
                    @include('filament.app.partials.floor-task-menu')
                    <span class="badge badge-outline tp-floor-receive__mode-chip">Verify</span>
                </div>

                <div
                    class="tp-floor-receive__progress-stats stats stats-horizontal bg-base-200 shadow"
                    aria-label="Today {{ $todayCount }}"
                    aria-live="polite"
                >
                    <div class="stat">
                        <div class="stat-title">Today</div>
                        <div class="stat-value text-2xl">{{ $todayCount }}</div>
                    </div>
                </div>
            </div>
        </header>

        <div class="tp-floor-receive__stage">
            <form
                x-on:submit.prevent="$wire.verifyScan($refs.scanInput.value)"
                x-init="$nextTick(() => $refs.scanInput?.focus())"
                class="tp-floor-receive__scan-form"
            >
                <div class="tp-floor-receive__scan-field">
                    <input
                        id="floor-scan-input"
                        type="text"
                        inputmode="none"
                        wire:model="scan"
                        x-ref="scanInput"
                        x-on:keydown.enter.prevent="$wire.verifyScan($refs.scanInput.value)"
                        autocomplete="off"
                        autofocus
                        class="tp-floor-receive__scan-input"
                        placeholder="Scan GTIN + serial (2D barcode)"
                        aria-label="Verify scan"
                        wire:loading.attr="disabled"
                    />
                </div>

                <button
                    type="button"
                    class="tp-floor-receive__camera-btn"
                    x-ref="cameraBtn"
                    :aria-label="cameraOn ? 'Close camera' : 'Open camera scanner'"
                    :aria-pressed="cameraOn ? 'true' : 'false'"
                    x-bind:disabled="starting"
                    wire:loading.attr="disabled"
                    x-on:click="toggleCamera()"
                >
                    <x-filament::icon icon="heroicon-o-qr-code" class="size-6" />
                </button>
            </form>

            <p x-show="cameraError" x-cloak x-text="cameraError" class="tp-floor-receive__camera-error" role="alert"></p>

            @if ($this->lastScanMessage)
                <div
                    role="status"
                    aria-live="{{ $this->lastScanTone === 'error' ? 'assertive' : 'polite' }}"
                    @class([
                        'tp-floor-receive__status',
                        'tp-floor-receive__status--ok' => $this->lastScanTone === 'ok',
                        'tp-floor-receive__status--warn' => $this->lastScanTone === 'warn',
                        'tp-floor-receive__status--error' => $this->lastScanTone === 'error',
                    ])
                >
                    <span class="tp-floor-receive__status-prefix" aria-hidden="true">
                        @if ($this->lastScanTone === 'ok') OK
                        @elseif ($this->lastScanTone === 'warn') Check
                        @else Error
                        @endif
                    </span>
                    <span class="tp-floor-receive__status-title">{{ $this->lastScanMessage }}</span>
                    @if ($this->lastScanDetail)
                        <span class="tp-floor-receive__status-detail">{{ $this->lastScanDetail }}</span>
                    @endif
                    @if ($url = $this->exceptionUrl())
                        <a href="{{ $url }}" class="tp-floor-receive__status-detail link">Open exception</a>
                    @endif
                </div>
            @endif

            <x-confirmed-scan-panel
                :rows="$verificationRows"
                heading="Today's verifications"
            />
        </div>

        <div class="tp-floor-receive__footer" role="group" aria-label="Verify actions">
            @if ($historyUrl = $this->verificationHistoryUrl())
                <a
                    href="{{ $historyUrl }}"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--cancel"
                >
                    Verification history
                </a>
            @endif
        </div>

        @include('filament.app.partials.floor-camera-overlay', [
            'stats' => [[
                'title' => 'Today',
                'value' => $todayCount,
            ]],
            'decode' => in_array($this->lastScanTone, ['ok', 'warn'], true) ? $this->lastScanDetail : null,
            'error' => $this->lastScanTone === 'error' ? $this->lastScanMessage : null,
        ])
    </div>
</x-filament-panels::page>
