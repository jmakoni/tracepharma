<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @include('filament.app.partials.ship-layout-switch', [
            'mode' => 'desktop',
            'desktopUrl' => $this->desktopShipUrl(),
            'floorUrl' => $this->floorShipUrl(),
        ])

        @if ($this->atpOutboundGateDisabled())
            <div role="alert" class="alert alert-warning" data-testid="atp-outbound-gate-disabled">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0" />
                <div class="flex flex-col gap-0.5">
                    <span class="font-semibold">ATP outbound gate is disabled</span>
                    <span class="text-sm">
                        Shipments are not being checked against the destination's ATP license for your
                        organization jurisdictions. Confirm the customer is an authorized trading
                        partner before sending, and ask an administrator to re-enable the gate.
                    </span>
                </div>
            </div>
        @endif

        @if ($this->readinessBadges() !== [])
            @include('filament.app.partials.outbound-ship-readiness', ['badges' => $this->readinessBadges()])
        @endif

        @if (! $this->isCompleted() && $this->getRecord()->status !== 'cancelled')
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
                        <span class="font-medium">{{ $this->getRecord()->site?->name ?? 'Ship from' }}</span>
                        @if ($this->getRecord()->tradingPartner)
                            <span aria-hidden="true">→</span>
                            <span class="font-medium">{{ $this->getRecord()->tradingPartner->name }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($this->isCorrective())
                            <span class="badge badge-lg badge-warning" title="{{ $this->correctiveReason() }}">
                                Corrective
                            </span>
                        @endif

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
                </div>

                @if ($this->isCorrective())
                    <div class="alert alert-warning" role="status">
                        <div class="text-sm">
                            <span class="font-semibold">Corrective ship order.</span>
                            Scans are authorized by prior ship evidence, not on-hand inventory at the ship-from site.
                            @if ($this->correctiveReason())
                                <span class="block">Reason: {{ $this->correctiveReason() }}</span>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow" aria-live="polite">
                    <div class="stat">
                        <div class="stat-title">Confirmed</div>
                        <div class="stat-value text-2xl">
                            {{ (int) $this->getRecord()->confirmed_count }}
                        </div>
                    </div>
                    @if ((int) $this->getRecord()->expected_count > 0)
                        <div class="stat">
                            <div class="stat-title">Expected</div>
                            <div class="stat-value text-2xl">
                                {{ (int) $this->getRecord()->expected_count }}
                            </div>
                            @if ($this->getRecord()->split_declared)
                                <div class="stat-desc">Split declared</div>
                            @elseif ((int) $this->getRecord()->confirmed_count < (int) $this->getRecord()->expected_count)
                                <div class="stat-desc">
                                    Residual {{ (int) $this->getRecord()->expected_count - (int) $this->getRecord()->confirmed_count }}
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($this->isCompleted())
                    <div class="{{ $this->shipCompletePanelClass() }}">
                        <div class="text-lg font-semibold">{{ $this->shipCompleteCopy()['title'] }}</div>
                        <p class="text-sm">
                            {{ $this->shipCompleteCopy()['body'] }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <span @class([
                                'badge badge-sm',
                                'badge-success' => $this->transmissionStatusBadgeColor() === 'success',
                                'badge-error' => $this->transmissionStatusBadgeColor() === 'danger',
                                'badge-warning' => $this->transmissionStatusBadgeColor() === 'warning',
                                'badge-ghost' => $this->transmissionStatusBadgeColor() === 'gray',
                            ])>
                                Transmit: {{ $this->transmissionStatusLabel() }}
                            </span>
                            @if ($this->getRecord()->epcisDocument?->outboundConnection)
                                <span class="text-gray-500 dark:text-gray-400">
                                    via {{ $this->getRecord()->epcisDocument->outboundConnection->name }}
                                </span>
                            @endif
                        </div>
                        @if ($this->getRecord()->epcisDocument)
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <a
                                    href="{{ $this->getRecord()->epcisDocument->filamentViewUrl() }}"
                                    class="link link-primary font-medium"
                                >
                                    View shipping EPCIS document
                                </a>
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    Use <strong>Download EPCIS</strong> in the page header to save the file.
                                </span>
                            </div>
                        @endif
                    </div>
                @elseif ($this->getRecord()->status === 'cancelled')
                    <div class="alert alert-warning">
                        <span>This ship order was cancelled.</span>
                    </div>
                @elseif ($this->wizardStep === 1)
                    @include('filament.app.partials.outbound-ship-wizard-step-scan', [
                        'scanInputId' => 'scan-input',
                        'showAddFromReceived' => true,
                    ])
                @elseif ($this->wizardStep === 2)
                    @include('filament.app.partials.outbound-ship-wizard-step-customer')
                @elseif ($this->wizardStep === 3)
                    @include('filament.app.partials.outbound-ship-wizard-step-send', [
                        'showAtpGateNote' => true,
                    ])
                @endif
            </div>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
