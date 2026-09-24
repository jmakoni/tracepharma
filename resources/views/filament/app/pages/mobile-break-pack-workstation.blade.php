<x-filament-panels::page>
    @assets
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}" data-tp-html5-qrcode="1"></script>
        <script src="{{ asset('js/tp-floor-receive.js') }}?v={{ @filemtime(public_path('js/tp-floor-receive.js')) ?: time() }}"></script>
    @endassets

    <x-scan-flash />

    <div class="tp-floor-receive__layout-switch">
        @include('filament.app.partials.floor-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopBreakPackUrl(),
            'floorUrl' => $this->floorBreakPackUrl(),
        ])
    </div>

    @php
        $selectedRows = collect($this->selectedScanRows())
            ->map(fn (array $row): array => [
                'id' => (int) ($row['epc_id'] ?? 0),
                'label' => (string) ($row['label'] ?? $row['identifier'] ?? ''),
                'type' => '',
                'can_remove' => true,
            ])
            ->values()
            ->all();
        $selectedIds = array_map('intval', $this->selectedChildIds);
    @endphp

    <div
        class="tp-floor-receive tp-floor-break-pack"
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
                        <div class="stat-value text-2xl">{{ count($selectedIds) }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Open</div>
                        <div class="stat-value text-2xl">{{ count($this->openChildren) }}</div>
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
                        placeholder="Scan source parent / child"
                        aria-label="Break and pack scan"
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
                    heading="Selected to break & pack"
                    remove-confirm="Deselect this child?"
                    remove-method="deselectChild"
                />
            @endif

            @if ($this->openChildren !== [])
                <fieldset class="flex flex-col gap-2 mt-3">
                    <legend class="text-sm font-medium">Children</legend>
                    @foreach ($this->openChildren as $childId => $label)
                        @php $isSelected = in_array((int) $childId, $selectedIds, true); @endphp
                        <button
                            type="button"
                            wire:click="toggleChild({{ (int) $childId }})"
                            @class([
                                'tp-floor-list__row text-start',
                                'ring-2 ring-success/40' => $isSelected,
                            ])
                        >
                            <span class="font-mono text-sm">{{ $isSelected ? '✓ ' : '' }}{{ $label }}</span>
                        </button>
                    @endforeach
                </fieldset>
            @endif
        </div>

        <div class="tp-floor-receive__footer" role="group" aria-label="Break and pack actions">
            @if ($selectedIds !== [])
                <button
                    type="button"
                    class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--confirm"
                    wire:click="mountAction('confirmBreakPack')"
                    wire:loading.attr="disabled"
                >
                    Confirm break & pack
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
                ['title' => 'Selected', 'value' => count($selectedIds)],
                ['title' => 'Open', 'value' => count($this->openChildren)],
            ],
            'decode' => $selectedRows === [] ? null : (string) ($selectedRows[array_key_last($selectedRows)]['label'] ?? ''),
        ])
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
