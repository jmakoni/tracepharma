<x-filament-panels::page>
    @assets
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}" data-tp-html5-qrcode="1"></script>
        <script src="{{ asset('js/tp-floor-receive.js') }}?v={{ @filemtime(public_path('js/tp-floor-receive.js')) ?: time() }}"></script>
    @endassets

    <x-scan-flash />

    <div class="tp-floor-receive__layout-switch">
        @include('filament.app.partials.floor-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopPackUrl(),
            'floorUrl' => $this->floorPackUrl(),
        ])
    </div>

    @php
        $childRows = collect($this->children)
            ->map(fn (array $child): array => [
                'id' => (int) $child['epc_id'],
                'label' => (string) $child['label'],
                'type' => '',
                'can_remove' => true,
            ])
            ->values()
            ->all();
    @endphp

    <div
        class="tp-floor-receive tp-floor-pack"
        x-data="tpFloorReceive(@js(\App\Support\Floor\FloorCameraScanAlpine::tpFloorReceiveConfig('processScan')))"
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
                    <span class="badge badge-outline tp-floor-receive__mode-chip">{{ $this->commissionSiteLabel() }}</span>
                </div>

                @if ($this->parentSscc18)
                    <p class="font-mono text-sm">Parent {{ $this->parentSscc18 }}</p>
                @endif

                <div
                    class="tp-floor-receive__progress-stats stats stats-horizontal bg-base-200 shadow"
                    aria-label="Children {{ count($this->children) }}"
                    aria-live="polite"
                >
                    <div class="stat">
                        <div class="stat-title">Children</div>
                        <div class="stat-value text-2xl">{{ count($this->children) }}</div>
                    </div>
                </div>
            </div>
        </header>

        <div class="tp-floor-receive__stage">
            <form
                wire:submit.prevent="processScan"
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
                        x-on:keydown.enter.prevent="$wire.processScan()"
                        autocomplete="off"
                        autofocus
                        class="tp-floor-receive__scan-input"
                        placeholder="{{ $this->parentLabelId ? 'Scan SGTIN or child SSCC' : 'Scan child or parent SSCC' }}"
                        aria-label="Pack scan"
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

            @if ($this->lastMessage)
                <div
                    role="status"
                    aria-live="{{ $this->lastTone === 'error' ? 'assertive' : 'polite' }}"
                    @class([
                        'tp-floor-receive__status',
                        'tp-floor-receive__status--ok' => $this->lastTone === 'ok',
                        'tp-floor-receive__status--warn' => $this->lastTone === 'warn',
                        'tp-floor-receive__status--error' => $this->lastTone === 'error',
                    ])
                >
                    <span class="tp-floor-receive__status-prefix" aria-hidden="true">
                        @if ($this->lastTone === 'ok') OK
                        @elseif ($this->lastTone === 'warn') Check
                        @else Error
                        @endif
                    </span>
                    <span class="tp-floor-receive__status-title">{{ $this->lastMessage }}</span>
                    @if ($this->batchUrl)
                        <a href="{{ $this->batchUrl }}" class="tp-floor-receive__status-detail link">Open batch</a>
                    @endif
                </div>
            @endif

            <x-confirmed-scan-panel
                :rows="$childRows"
                heading="Children to pack"
                remove-confirm="Remove this child from the pack list?"
                remove-method="removeChild"
            />

            @if ($this->children === [])
                <p class="tp-floor-receive__complete-reason">Scan at least one child, then confirm pack.</p>
            @endif
        </div>

        <div class="tp-floor-receive__footer" role="group" aria-label="Pack actions">
            @if ($this->children !== [])
                <button
                    type="button"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--confirm"
                    wire:click="mountAction('confirmPack')"
                    wire:loading.attr="disabled"
                >
                    {{ $this->parentLabelId ? 'Add to SSCC' : 'Confirm pack' }}
                </button>
                <button
                    type="button"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--cancel"
                    wire:click="clearChildren"
                    wire:loading.attr="disabled"
                >
                    Clear list
                </button>
            @endif
        </div>

        @include('filament.app.partials.floor-camera-overlay', [
            'stats' => [[
                'title' => 'Children',
                'value' => count($this->children),
            ]],
            'decode' => $this->children === [] ? null : (string) ($this->children[array_key_last($this->children)]['label'] ?? ''),
        ])
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
