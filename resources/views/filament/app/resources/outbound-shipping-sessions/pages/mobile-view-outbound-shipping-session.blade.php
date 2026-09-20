<x-filament-panels::page>
    @assets
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}" data-tp-html5-qrcode="1"></script>
        <script src="{{ asset('js/tp-floor-receive.js') }}?v={{ @filemtime(public_path('js/tp-floor-receive.js')) ?: time() }}"></script>
    @endassets

    <x-scan-flash />

    <div class="tp-floor-receive__layout-switch">
        @include('filament.app.partials.ship-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopShipUrl(),
            'floorUrl' => $this->floorShipUrl(),
        ])
    </div>

    @php
        $cookieName = \App\Support\Shipping\ShipLayout::COOKIE;
        $desktopUrl = $this->desktopShipUrl();
        $isCompleted = $this->isCompleted();
        $isCancelled = $this->isCancelled();
        $completeCopy = $isCompleted ? $this->shipCompleteCopy() : null;
    @endphp

    <div
        class="tp-floor-receive tp-floor-ship"
        x-data="tpFloorReceive(@js(\App\Support\Floor\FloorCameraScanAlpine::tpFloorReceiveConfig('stageScan')))"
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
                    <span class="badge badge-outline tp-floor-receive__mode-chip">Ship</span>
                </div>

                @if ($this->chipDeaLabel)
                    <span @class([
                        'badge badge-outline tp-floor-receive__mode-chip',
                        'badge-error' => $this->chipDeaColor === 'danger',
                        'badge-warning' => $this->chipDeaColor === 'warning',
                    ])>{{ $this->chipDeaLabel }}</span>
                @endif

                <p class="text-sm font-medium text-base-content/80">{{ $this->routeDisplayLabel() }}</p>

                <div
                    class="tp-floor-receive__progress-stats stats stats-horizontal bg-base-200 shadow"
                    aria-label="Confirmed {{ $this->confirmedCount() }}"
                    aria-live="polite"
                >
                    <div class="stat">
                        <div class="stat-title">Confirmed</div>
                        <div class="stat-value text-2xl">{{ $this->confirmedCount() }}</div>
                    </div>
                </div>
            </div>
        </header>

        @if ($isCompleted)
            <div class="{{ $this->shipFloorCompleteClass() }}">
                <div class="tp-floor-receive__complete-title">{{ $completeCopy['title'] }}</div>
                <p class="tp-floor-receive__complete-body">
                    {{ $completeCopy['body'] }}
                </p>
                @if ($this->outboundShippingSession()->canVoid())
                    <button
                        type="button"
                        class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--warning tp-floor-receive__complete-exit min-h-14"
                        wire:click="mountAction('voidShipOrder')"
                    >
                        Void shipment
                    </button>
                @endif
                <a href="{{ $this->shippingListUrl() }}" class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--ready tp-floor-receive__complete-exit">
                    Back to ship orders
                </a>
            </div>
        @elseif ($isCancelled)
            <div class="tp-floor-receive__complete tp-floor-receive__complete--cancelled">
                <div class="tp-floor-receive__complete-title">Ship order cancelled</div>
                <p class="tp-floor-receive__complete-body">
                    This order is closed. Open the desktop view for audit details.
                </p>
                <a href="{{ $this->shippingListUrl() }}" class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--ready tp-floor-receive__complete-exit">
                    Back to ship orders
                </a>
            </div>
        @else
            <div class="tp-floor-receive__stage">
                <form
                    wire:submit.prevent="stageScan"
                    x-init="$nextTick(() => $refs.scanInput?.focus())"
                    class="tp-floor-receive__scan-form"
                >
                    <div class="tp-floor-receive__scan-field">
                        <input
                            id="floor-scan-input"
                            type="text"
                            inputmode="none"
                            wire:model.live.blur="scan"
                            x-ref="scanInput"
                            x-on:keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"
                            autocomplete="off"
                            autofocus
                            class="tp-floor-receive__scan-input"
                            placeholder="Scan barcode"
                            aria-label="Scan to confirm ship order"
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
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                        </svg>
                        <span class="tp-floor-receive__camera-btn-label">Camera</span>
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
                    </div>
                @endif

                <x-confirmed-scan-panel
                    :rows="$this->recentConfirmedScanRows()"
                    :caption="$this->recentScansCaption()"
                    remove-confirm="Remove this scan from the ship order?"
                />

                @if ($this->confirmedCount() === 0)
                    <p class="tp-floor-receive__complete-reason">
                        Scan at least one item, then finish customer and send on desktop.
                    </p>
                @endif
            </div>

            <div class="tp-floor-receive__footer" role="group" aria-label="Ship actions">
                <a
                    href="{{ $desktopUrl }}"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--confirm"
                    onclick="document.cookie='{{ $cookieName }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
                >
                    Customer &amp; send
                </a>

                <a
                    href="{{ $this->shippingListUrl() }}"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--cancel"
                >
                    Back to ship orders
                </a>
            </div>
        @endif

        @include('filament.app.partials.floor-camera-overlay', [
            'stats' => [[
                'title' => 'Confirmed',
                'value' => $this->confirmedCount(),
            ]],
            'decode' => in_array($this->lastScanTone, ['ok', 'warn'], true) ? $this->lastScanDetail : null,
            'error' => $this->lastScanTone === 'error' ? $this->lastScanMessage : null,
        ])
    </div>
</x-filament-panels::page>
