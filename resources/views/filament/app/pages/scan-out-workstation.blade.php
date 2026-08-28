<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($this->sessionId === null)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <h2 class="card-title text-base">Open a ship order</h2>
                    <p class="text-sm opacity-70">
                        Pick an open order or start a new one. The existing Ship Order screen is unchanged.
                    </p>

                    @forelse ($this->openSessions() as $openSession)
                        <button
                            type="button"
                            class="btn btn-outline justify-start min-h-14"
                            wire:click="selectSession({{ (int) $openSession->getKey() }})"
                        >
                            #{{ $openSession->getKey() }}
                            · {{ $openSession->tradingPartner?->name ?? $openSession->site?->name ?? 'No customer' }}
                        </button>
                    @empty
                        <p class="text-sm opacity-70">No open ship orders. Start one from the header.</p>
                    @endforelse
                </div>
            </div>
        @else
            @php($session = $this->session())
            <div
                x-data="{ flashTone: null }"
                x-on:scan-result.window="
                    flashTone = $event.detail.tone;
                    setTimeout(() => { flashTone = null }, 700)
                "
                :class="{
                    'ring-4 ring-success/40': flashTone === 'ok',
                    'ring-4 ring-warning/40': flashTone === 'warn',
                    'ring-4 ring-error/40': flashTone === 'error',
                }"
                class="card bg-base-100 shadow-xl transition-shadow"
            >
                <div class="card-body gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="badge badge-outline">Ship order</span>
                            <span>{{ $session?->tradingPartner?->name ?? $session?->site?->name ?? 'Ship-from site' }}</span>
                        </div>
                        <span class="badge badge-lg badge-outline">{{ $this->statusLabel() }}</span>
                    </div>

                    @if ($this->readinessBadges() !== [])
                        @include('filament.app.partials.outbound-ship-readiness', ['badges' => $this->readinessBadges()])
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2 text-sm">
                        <div>
                            <span class="opacity-70">ASN</span>
                            <div class="font-mono">{{ $session?->asn_number ?: '—' }}</div>
                        </div>
                        <div>
                            <span class="opacity-70">Customer PO</span>
                            <div class="font-mono">{{ $session?->customer_po ?: '—' }}</div>
                        </div>
                        <div>
                            <span class="opacity-70">Invoice</span>
                            <div class="font-mono">{{ $session?->invoice_number ?: '—' }}</div>
                        </div>
                        <div>
                            <span class="opacity-70">TI/TS affirmation</span>
                            <div>{{ $session?->dscsa_affirm ? 'Yes' : 'No' }}</div>
                        </div>
                    </div>

                    <div class="stats bg-base-200 shadow">
                        <div class="stat">
                            <div class="stat-title">Confirmed</div>
                            <div class="stat-value text-2xl">{{ $this->confirmedLineCount() }}</div>
                        </div>
                    </div>

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

                    @if ($session?->canScan())
                        <x-scan-field
                            variant="desktop"
                            :show-camera="false"
                            input-id="scan-out-input"
                            label="Scan barcode"
                            placeholder="Scan SSCC or SGTIN"
                            confirm-label="ADD"
                            submit-action="confirmScan"
                        />
                    @endif

                    <button
                        type="button"
                        class="btn btn-ghost btn-sm self-start"
                        wire:click="selectSession(0)"
                    >
                        Change session
                    </button>
                </div>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
