<x-filament-panels::page>
    @assets
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}" data-tp-html5-qrcode="1"></script>
        <script src="{{ asset('js/tp-floor-receive.js') }}?v={{ @filemtime(public_path('js/tp-floor-receive.js')) ?: time() }}"></script>
    @endassets

    <x-scan-flash />

    <div class="tp-floor-receive__layout-switch">
        @include('filament.app.partials.floor-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopUnpackUrl(),
            'floorUrl' => $this->floorUnpackUrl(),
        ])
    </div>

    @php
        $selectedRows = collect($this->selectedScanRows())
            ->map(fn (array $row): array => [
                'id' => (int) ($row['epc_id'] ?? 0),
                'label' => (string) ($row['identifier'] ?? $row['label'] ?? ''),
                'type' => '',
                'can_remove' => true,
            ])
            ->values()
            ->all();
        $containerChildren = $this->parentEpcId ? $this->containerChildren() : [];
    @endphp

    <div
        class="tp-floor-receive tp-floor-unpack"
        x-data="tpFloorReceive(@js(\App\Support\Floor\FloorCameraScanAlpine::tpFloorReceiveConfig('processScan')))"
        x-on:destroy="stopCamera()"
        @keydown.escape.window="if (cameraOn) { stopCamera() }"
        x-on:focus-scan.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
    >
        <header class="tp-floor-receive__sticky-header">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="tp-floor-receive__header-top">
                    @include('filament.app.partials.floor-task-menu')
                    <span class="badge badge-outline">{{ $this->commissionSiteLabel() }}</span>
                </div>
                @if ($this->parentEpcId)
                    <p class="font-mono text-sm">{{ $this->parentLabel }}</p>
                @endif
                <div class="tp-floor-receive__progress-stats stats stats-horizontal bg-base-200 shadow">
                    <div class="stat">
                        <div class="stat-title">Selected</div>
                        <div class="stat-value text-2xl">{{ $this->selectedCount() }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Open</div>
                        <div class="stat-value text-2xl">{{ $this->openChildrenCount() }}</div>
                    </div>
                </div>
            </div>
        </header>

        <div class="tp-floor-receive__stage">
            <form x-on:submit.prevent="$wire.processScan($refs.scanInput.value)" class="tp-floor-receive__scan-form">
                <div class="tp-floor-receive__scan-field">
                    <input
                        type="text"
                        inputmode="none"
                        wire:model="scan"
                        x-ref="scanInput"
                        x-on:keydown.enter.prevent="$wire.processScan($refs.scanInput.value)"
                        autocomplete="off"
                        autofocus
                        class="tp-floor-receive__scan-input"
                        placeholder="{{ $this->parentEpcId ? 'Scan child to toggle' : 'Scan parent SSCC / child' }}"
                        aria-label="Unpack scan"
                        wire:loading.attr="disabled"
                    />
                </div>
                <button
                    type="button"
                    class="tp-floor-receive__camera-btn"
                    aria-label="Open camera"
                    x-on:click="toggleCamera()"
                    x-bind:disabled="starting"
                    wire:loading.attr="disabled"
                >
                    <x-filament::icon icon="heroicon-o-qr-code" class="size-6" />
                </button>
            </form>

            @if ($this->lastMessage)
                <div
                    role="status"
                    @class([
                        'tp-floor-receive__status',
                        'tp-floor-receive__status--ok' => $this->lastTone === 'ok',
                        'tp-floor-receive__status--warn' => $this->lastTone === 'warn',
                        'tp-floor-receive__status--error' => $this->lastTone === 'error',
                    ])
                >
                    <span class="tp-floor-receive__status-title">{{ $this->lastMessage }}</span>
                </div>
            @endif

            @if ($selectedRows !== [])
                <x-confirmed-scan-panel
                    :rows="$selectedRows"
                    heading="Selected to unpack"
                    remove-confirm="Return this child to the container?"
                    remove-method="toggleChild"
                />
            @endif

            @if ($containerChildren !== [])
                <fieldset class="flex flex-col gap-2 mt-3">
                    <legend class="text-sm font-medium">Still in container</legend>
                    @foreach ($containerChildren as $childId => $label)
                        <button
                            type="button"
                            wire:click="toggleChild({{ (int) $childId }})"
                            class="tp-floor-list__row text-start"
                        >
                            <span class="font-mono text-sm">{{ $label }}</span>
                        </button>
                    @endforeach
                </fieldset>
            @endif
        </div>

        <div class="tp-floor-receive__footer" role="group" aria-label="Unpack actions">
            @if ($this->selectedCount() > 0)
                <button
                    type="button"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--confirm"
                    wire:click="mountAction('confirmUnpack')"
                    wire:loading.attr="disabled"
                >
                    Confirm unpack ({{ $this->selectedCount() }})
                </button>
            @endif
            @if ($this->parentEpcId)
                <button
                    type="button"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--cancel"
                    wire:click="clearParent"
                >
                    Clear
                </button>
            @endif
        </div>

        @include('filament.app.partials.floor-camera-overlay', [
            'stats' => [
                ['title' => 'Selected', 'value' => $this->selectedCount()],
                ['title' => 'Open', 'value' => $this->openChildrenCount()],
            ],
            'decode' => $selectedRows === [] ? null : (string) ($selectedRows[array_key_last($selectedRows)]['label'] ?? ''),
        ])
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
