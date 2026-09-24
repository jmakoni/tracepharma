<div class="min-h-dvh bg-base-200 pb-20">
    @include('filament.app.partials.floor-layout-switch', [
        'mode' => 'floor',
        'desktopUrl' => $this->desktopFindUrl(),
        'floorUrl' => $this->floorFindUrl(),
    ])

    <x-scan-flash />

    <div
        class="tp-floor-receive tp-floor-find"
        x-data="tpFloorReceive(@js(\App\Support\Floor\FloorCameraScanAlpine::tpFloorReceiveConfig('runTrace')))"
        x-on:destroy="stopCamera()"
        @keydown.escape.window="if (cameraOn) { stopCamera() }"
        x-on:focus-scan.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
        x-on:livewire:error.window="connectionError = 'Scan needs a connection'"
    >
        <header class="tp-floor-receive__sticky-header px-4 pt-4">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="tp-floor-receive__header-top">
                    @include('filament.app.partials.floor-task-menu')
                    <span class="badge badge-outline tp-floor-receive__mode-chip">Find</span>
                </div>
                <h1 class="text-2xl font-semibold text-base-content">Find</h1>
            </div>
        </header>

        <div class="tp-floor-receive__stage px-4">
            <p
                x-show="connectionError"
                x-cloak
                x-text="connectionError"
                class="tp-floor-receive__camera-error mb-2"
                role="alert"
            ></p>

            <form
                x-on:submit.prevent="$wire.runTrace($refs.scanInput.value)"
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
                        x-on:keydown.enter.prevent="$wire.runTrace($refs.scanInput.value)"
                        autocomplete="off"
                        autofocus
                        class="tp-floor-receive__scan-input"
                        placeholder="Scan SGTIN or SSCC"
                        aria-label="Find scan"
                        wire:loading.attr="disabled"
                    />
                </div>

                <button
                    type="button"
                    class="tp-floor-receive__camera-btn"
                    x-ref="cameraBtn"
                    :aria-label="cameraOn ? 'Close camera' : 'Open camera scanner'"
                    :aria-pressed="cameraOn ? 'true' : 'false'"
                    x-on:click="toggleCamera()"
                >
                    <x-filament::icon icon="heroicon-o-qr-code" class="size-6" />
                </button>
            </form>

            <p x-show="cameraError" x-cloak x-text="cameraError" class="tp-floor-receive__camera-error" role="alert"></p>

            @if ($this->trace && ! ($this->trace['found'] ?? false))
                <div role="alert" class="tp-floor-receive__status tp-floor-receive__status--error mt-3">
                    <span class="tp-floor-receive__status-prefix" aria-hidden="true">Error</span>
                    <span class="tp-floor-receive__status-title">No asset found</span>
                    <span class="tp-floor-receive__status-detail">{{ $this->trace['scan'] ?? $this->scan }}</span>
                </div>
            @endif

            @if ($this->trace && ($this->trace['found'] ?? false))
                <div class="mt-4 rounded-2xl border border-base-300 bg-base-100 p-4 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge badge-outline">{{ $this->trace['status'] ?? '—' }}</span>
                        @if (filled($this->trace['disposition'] ?? null))
                            <span class="badge badge-ghost">{{ $this->trace['disposition'] }}</span>
                        @endif
                    </div>
                    <p class="mt-2 font-mono text-base font-semibold break-all">
                        {{ $this->trace['primary_identifier'] ?? $this->scan }}
                    </p>
                    @if (filled($this->trace['product']['name'] ?? null))
                        <p class="mt-1 text-sm text-base-content/80">{{ $this->trace['product']['name'] }}</p>
                    @endif
                    @if (filled($this->trace['lot']['lot_number'] ?? null))
                        <p class="text-sm text-base-content/70">Lot {{ $this->trace['lot']['lot_number'] }}</p>
                    @endif
                    @if (filled($this->trace['last_seen_at'] ?? null))
                        <p class="mt-2 text-xs text-base-content/60">Last seen {{ $this->trace['last_seen_at'] }}</p>
                    @endif
                    @if ((int) ($this->trace['children_count'] ?? 0) > 0)
                        <p class="text-xs text-base-content/60">{{ $this->trace['children_count'] }} children</p>
                    @endif
                </div>
            @endif
        </div>

        @include('filament.app.partials.floor-camera-overlay', [
            'stats' => [],
            'decode' => null,
            'error' => null,
        ])
    </div>

    @include('filament.app.partials.floor-bottom-nav', ['active' => 'profile'])
</div>

<script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}" data-tp-html5-qrcode="1"></script>
<script src="{{ asset('js/tp-floor-receive.js') }}?v={{ @filemtime(public_path('js/tp-floor-receive.js')) ?: time() }}"></script>
