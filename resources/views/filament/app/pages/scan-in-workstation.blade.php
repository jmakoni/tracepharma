<x-filament-panels::page>
    <x-scanner-desk>
        <x-slot:header>
            @if ($this->sessionId !== null)
                @php($session = $this->session())
                <x-scanner-desk-header>
                    <x-slot:context>
                        <span class="badge badge-lg badge-outline font-semibold">
                            Site: {{ $this->contextSiteLabel() }}
                        </span>
                        <span class="badge badge-outline">{{ $this->kindBadgeLabel() }}</span>
                        <span class="badge badge-outline">{{ $this->edgeModeChipLabel() }}</span>
                        <span class="badge badge-lg badge-outline">{{ $this->statusLabel() }}</span>
                        @include('filament.app.partials.expected-order-header', [
                            'header' => $this->expectedOrderHeader(),
                        ])
                    </x-slot:context>

                    <x-slot:qty>
                        @if ($progress = $this->sessionProgress())
                            @include('filament.app.partials.receiving-session-progress-stats', [
                                'progress' => $progress,
                                'class' => 'stats stats-horizontal bg-base-200 shadow',
                            ])
                        @endif
                    </x-slot:qty>

                    <x-slot:alert>
                        @if ($this->lastScanMessage)
                            <div
                                role="status"
                                aria-live="{{ $this->lastScanTone === 'error' ? 'assertive' : 'polite' }}"
                                @class([
                                    'alert',
                                    'alert-success' => $this->lastScanTone === 'ok',
                                    'alert-warning' => $this->lastScanTone === 'warn',
                                    'alert-error' => $this->lastScanTone === 'error',
                                ])
                            >
                                <span class="font-semibold">{{ $this->lastScanMessage }}</span>
                            </div>
                        @endif
                    </x-slot:alert>

                    <x-slot:scan>
                        @if ($session?->status === 'completed')
                            <div class="rounded-lg border border-success/30 bg-success/10 p-4">
                                <div class="text-lg font-semibold">{{ $this->promptCopy()['completeTitle'] }}</div>
                                <p class="text-sm">{{ $this->promptCopy()['completeBody'] }}</p>
                            </div>
                        @else
                            <x-scan-field
                                variant="desktop"
                                :show-camera="false"
                                input-id="scan-in-input"
                                label="Scan barcode"
                                :placeholder="$this->promptCopy()['scanHelper']"
                                :confirm-label="$this->promptCopy()['confirmButton']"
                                submit-action="confirmScan"
                            />
                        @endif
                    </x-slot:scan>
                </x-scanner-desk-header>
            @endif
        </x-slot:header>

        @if ($this->sessionId === null)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <h2 class="card-title text-base">Open a receive session</h2>
                    <p class="text-sm opacity-70">
                        Pick an open inbound or start scan-first. The existing Receive screen is unchanged.
                    </p>

                    <button
                        type="button"
                        class="btn btn-primary min-h-14"
                        wire:click="startScanFirstFromPicker"
                        wire:loading.attr="disabled"
                    >
                        Start scan-first
                    </button>

                    @forelse ($this->openSessions() as $openSession)
                        <button
                            type="button"
                            class="btn btn-outline justify-start min-h-14"
                            wire:click="selectSession({{ (int) $openSession->getKey() }})"
                        >
                            #{{ $openSession->getKey() }}
                            · {{ $openSession->session_kind?->badgeLabel() ?? 'Receive' }}
                            · {{ $openSession->tradingPartner?->name ?? $openSession->site?->name ?? 'No partner' }}
                        </button>
                    @empty
                        <p class="text-sm opacity-70">No open receive sessions yet. Use Start scan-first above.</p>
                    @endforelse
                </div>
            </div>
        @else
            @php($session = $this->session())
            @php($confirmedRows = $this->confirmedScanRows())

            <x-scanner-confirmed-table
                :rows="$confirmedRows"
                :title="'Confirmed ('.$confirmedRows->count().')'"
                empty="Scan barcodes to build the receive list."
                :can-remove="$session?->status !== 'completed'"
                remove-method="removeConfirmed"
                id-key="line_id"
            />

            <button
                type="button"
                class="btn btn-ghost btn-sm self-start"
                wire:click="selectSession(0)"
            >
                Change session
            </button>
        @endif
    </x-scanner-desk>

    <x-filament-actions::modals />
</x-filament-panels::page>
