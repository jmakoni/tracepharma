<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($this->sessionId === null)
            @if ($this->showSitePicker)
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body gap-4">
                        <h2 class="card-title text-base">Ship from site</h2>
                        <p class="text-sm opacity-70">
                            Choose where inventory is shipping from. Scans must match on-hand stock at this site.
                        </p>

                        @foreach ($this->shipFromSiteOptions() as $siteId => $siteName)
                            <button
                                type="button"
                                class="btn btn-outline justify-start min-h-14"
                                wire:click="openNewSession({{ (int) $siteId }})"
                            >
                                {{ $siteName }}
                            </button>
                        @endforeach

                        <button
                            type="button"
                            class="btn btn-ghost btn-sm self-start"
                            wire:click="cancelNewShipOrder"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            @else
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body gap-4">
                        <h2 class="card-title text-base">Open a ship order</h2>
                        <p class="text-sm opacity-70">
                            Pick an open order or start a new one. Set customer, ASN, and send from the wizard steps.
                        </p>

                        @forelse ($this->openSessions() as $openSession)
                            <button
                                type="button"
                                class="btn btn-outline justify-start min-h-14"
                                wire:click="selectSession({{ (int) $openSession->getKey() }})"
                            >
                                #{{ $openSession->getKey() }}
                                · {{ $openSession->site?->name ?? 'Ship-from site' }}
                                @if ($openSession->tradingPartner?->name)
                                    → {{ $openSession->tradingPartner->name }}
                                @endif
                            </button>
                        @empty
                            <p class="text-sm opacity-70">No open ship orders. Use <strong>New ship order</strong> in the header.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        @else
            @php($session = $this->session())

            @if ($this->atpOutboundGateDisabled())
                <div role="alert" class="alert alert-warning" data-testid="atp-outbound-gate-disabled">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 shrink-0" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-semibold">ATP outbound gate is disabled</span>
                        <span class="text-sm">
                            Shipments are not being checked against the destination's ATP license. Confirm the
                            customer is authorized before sending.
                        </span>
                    </div>
                </div>
            @endif

            @if ($this->readinessBadges() !== [])
                @include('filament.app.partials.outbound-ship-readiness', ['badges' => $this->readinessBadges()])
            @endif

            @if ($session && ! $this->isCompleted() && $session->status !== 'cancelled')
                @include('filament.app.partials.outbound-ship-wizard-nav')
            @endif

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
                            <span class="badge badge-outline">Ship order #{{ $session?->getKey() }}</span>
                            <span class="font-medium">{{ $session?->site?->name ?? 'Ship from' }}</span>
                            @if ($session?->tradingPartner)
                                <span aria-hidden="true">→</span>
                                <span class="font-medium">{{ $session->tradingPartner->name }}</span>
                            @endif
                        </div>
                        <span @class([
                            'badge badge-lg',
                            'badge-success' => $this->statusBadgeColor() === 'success',
                            'badge-info' => $this->statusBadgeColor() === 'info',
                            'badge-warning' => $this->statusBadgeColor() === 'warning',
                            'badge-outline' => $this->statusBadgeColor() === 'outline',
                            'badge-ghost' => $this->statusBadgeColor() === 'gray',
                        ])>
                            {{ $this->statusLabel() }}
                        </span>
                    </div>

                    <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow" aria-live="polite">
                        <div class="stat">
                            <div class="stat-title">Confirmed</div>
                            <div class="stat-value text-2xl">{{ $this->confirmedCount() }}</div>
                        </div>
                        @if ($session && (int) $session->expected_count > 0)
                            <div class="stat">
                                <div class="stat-title">Expected</div>
                                <div class="stat-value text-2xl">{{ (int) $session->expected_count }}</div>
                            </div>
                        @endif
                    </div>

                    @if ($session && $this->isCompleted())
                        <div class="{{ $this->shipCompletePanelClass() }}">
                            <div class="text-lg font-semibold">{{ $this->shipCompleteCopy()['title'] }}</div>
                            <p class="text-sm">{{ $this->shipCompleteCopy()['body'] }}</p>
                        </div>
                    @elseif ($session && $session->status === 'cancelled')
                        <div class="alert alert-warning">
                            <span>This ship order was cancelled.</span>
                        </div>
                    @elseif ($this->wizardStep === 1)
                        @include('filament.app.partials.outbound-ship-wizard-step-scan', [
                            'scanInputId' => 'scan-out-input',
                            'useScanFieldComponent' => true,
                        ])
                    @elseif ($this->wizardStep === 2)
                        @include('filament.app.partials.outbound-ship-wizard-step-customer')
                    @elseif ($this->wizardStep === 3)
                        @include('filament.app.partials.outbound-ship-wizard-step-send', [
                            'showAtpGateNote' => true,
                        ])
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
