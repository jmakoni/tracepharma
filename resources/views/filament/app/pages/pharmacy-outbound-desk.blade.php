<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($this->sessionId === null)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <h2 class="card-title text-base">Open a low-volume outbound</h2>
                    <p class="text-sm opacity-70">Ship Order and Scan Out stay locked. This desk authors the same ship session.</p>
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
                        <p class="text-sm opacity-70">No open outbounds. Start one from the header.</p>
                    @endforelse
                </div>
            </div>
        @else
            @php($session = $this->session())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="badge badge-outline">Pharmacy outbound</span>
                        <span class="badge badge-lg badge-outline">{{ $this->statusLabel() }}</span>
                    </div>

                    @if ($this->readinessBadges() !== [])
                        @include('filament.app.partials.outbound-ship-readiness', ['badges' => $this->readinessBadges()])
                    @endif

                    @if ($session === null)
                    <div class="rounded-lg border border-warning/30 bg-warning/10 p-4">
                        <div class="text-lg font-semibold">Outbound not found</div>
                        <p class="text-sm">This session is no longer available. Pick another outbound or start a new one.</p>
                    </div>
                    @elseif (in_array($session->status, ['open', 'in_progress'], true))
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="form-control gap-1">
                            <span class="label-text text-sm">Customer</span>
                            <select wire:model.live="tradingPartnerId" class="select select-bordered">
                                <option value="">Select partner</option>
                                @foreach ($this->partnerOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="form-control gap-1">
                            <span class="label-text text-sm">Destination site</span>
                            <select wire:model="shipToSiteId" class="select select-bordered">
                                <option value="">{{ $this->destSiteOptions() === [] ? 'Save TI / refs to create a site' : 'Select dest site' }}</option>
                                @foreach ($this->destSiteOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="form-control gap-1">
                            <span class="label-text text-sm">Dest GLN</span>
                            <input type="text" wire:model="destGln" class="input input-bordered font-mono" placeholder="13-digit GLN" />
                        </label>
                        <label class="form-control gap-1">
                            <span class="label-text text-sm">Dest SGLN</span>
                            <input type="text" wire:model="destSgln" class="input input-bordered font-mono" placeholder="urn:epc:id:sgln:…" />
                        </label>
                        <label class="form-control gap-1">
                            <span class="label-text text-sm">ASN</span>
                            <input type="text" wire:model="asn" class="input input-bordered font-mono" />
                        </label>
                        <label class="form-control gap-1">
                            <span class="label-text text-sm">PO</span>
                            <input type="text" wire:model="po" class="input input-bordered font-mono" />
                        </label>
                        <label class="label cursor-pointer justify-start gap-2">
                            <input type="checkbox" wire:model="dscsaAffirm" class="checkbox" />
                            <span class="label-text">TI/TS affirmation</span>
                        </label>
                    </div>
                    <button type="button" class="btn btn-outline min-h-12 self-start" wire:click="mountAction('saveRefs')">
                        Save TI / refs
                    </button>
                    @if ($this->sendBlockers() !== [])
                        <div role="alert" class="rounded-lg border border-warning/30 bg-warning/10 p-4">
                            <div class="text-sm font-semibold">Send TI is blocked</div>
                            <ul class="mt-2 list-disc pl-5 text-sm">
                                @foreach ($this->sendBlockers() as $blocker)
                                    <li>{{ $blocker }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @else
                    <div class="rounded-lg border border-success/30 bg-success/10 p-4">
                        <div class="text-lg font-semibold">Outbound sent</div>
                        <p class="text-sm">This TI session is complete. Ship Order stays locked for pharmacy.</p>
                    </div>
                    @endif

                    <div class="stats bg-base-200 shadow">
                        <div class="stat">
                            <div class="stat-title">Confirmed</div>
                            <div class="stat-value text-2xl">{{ $this->confirmedLineCount() }}</div>
                        </div>
                    </div>

                    @if ($this->lastScanMessage)
                        <div
                            role="status"
                            @class([
                                'alert',
                                'alert-success' => $this->lastScanTone === 'ok',
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
                            input-id="pharmacy-out-input"
                            label="Scan barcode"
                            placeholder="Scan SSCC or SGTIN"
                            confirm-label="ADD"
                            submit-action="confirmScan"
                        />
                    @endif

                    <button type="button" class="btn btn-ghost btn-sm self-start" wire:click="selectSession(0)">
                        Change session
                    </button>
                </div>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
