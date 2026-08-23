<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($this->sessionId === null)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <h2 class="card-title text-base">Open a receive session</h2>
                    <p class="text-sm opacity-70">
                        Pick an open inbound or start scan-first. The existing Receive screen is unchanged.
                    </p>

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
                        <p class="text-sm opacity-70">No open receive sessions. Start scan-first from the header.</p>
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
                            <span class="badge badge-outline">{{ $this->kindBadgeLabel() }}</span>
                            <span class="badge badge-outline">{{ $this->edgeModeChipLabel() }}</span>
                            <span>{{ $session?->tradingPartner?->name ?? $session?->site?->name ?? 'Receive site' }}</span>
                        </div>
                        <span class="badge badge-lg badge-outline">{{ $this->statusLabel() }}</span>
                    </div>

                    <p class="text-sm opacity-70">{{ $this->promptCopy()['kindHelper'] }}</p>

                    <div class="stats bg-base-200 shadow">
                        <div class="stat">
                            <div class="stat-title">Confirmed</div>
                            <div class="stat-value text-2xl">{{ $this->confirmedLineCount() }}</div>
                        </div>
                    </div>

                    @if ($this->caseRows()->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Cases in this SSCC</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->caseRows() as $case)
                                        <tr>
                                            <td class="font-mono text-sm">
                                                @if ($case['confirmed'])
                                                    <span class="text-success font-bold" aria-label="Confirmed">✓</span>
                                                @endif
                                                {{ $case['label'] }}
                                            </td>
                                            <td class="text-right">
                                                @if ($case['confirmed'] && $session?->status !== 'completed')
                                                    <button
                                                        type="button"
                                                        class="btn btn-ghost btn-sm min-h-12"
                                                        wire:click="removeCase({{ $case['line_id'] }})"
                                                    >
                                                        Remove
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

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
