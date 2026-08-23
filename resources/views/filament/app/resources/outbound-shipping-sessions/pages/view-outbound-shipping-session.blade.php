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
                        Shipments are not being checked against the destination's ATP license for the
                        organization receiving state. Confirm the customer is an authorized trading
                        partner before sending, and ask an administrator to re-enable the gate.
                    </span>
                </div>
            </div>
        @endif

        @if (! $this->isCompleted() && $this->getRecord()->status !== 'cancelled')
            @php
                $shipWizardSteps = [
                    1 => ['label' => 'Scan', 'description' => 'Confirm shippable EPCs'],
                    2 => ['label' => 'Customer', 'description' => 'Partner and ship-to'],
                    3 => ['label' => 'Send', 'description' => 'ASN, refs, TI/TS'],
                ];
            @endphp
            <div class="fi-sc-wizard fi-contained rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <nav class="fi-sc-wizard-header" aria-label="Ship order steps">
                    @foreach ($shipWizardSteps as $stepNumber => $stepMeta)
                        <div
                            @class([
                                'fi-sc-wizard-header-step',
                                'fi-active' => $this->wizardStep === $stepNumber,
                                'fi-completed' => $this->wizardStep > $stepNumber,
                            ])
                        >
                            <button
                                type="button"
                                wire:click="goToStep({{ $stepNumber }})"
                                class="fi-sc-wizard-header-step-btn"
                            >
                                <div class="fi-sc-wizard-header-step-icon-ctn">
                                    @if ($this->wizardStep > $stepNumber)
                                        <x-filament::icon
                                            icon="heroicon-o-check"
                                            class="h-5 w-5 text-primary-600 dark:text-primary-400"
                                        />
                                    @else
                                        <span class="fi-sc-wizard-header-step-number">
                                            {{ str_pad((string) $stepNumber, 2, '0', STR_PAD_LEFT) }}
                                        </span>
                                    @endif
                                </div>

                                <div class="fi-sc-wizard-header-step-text">
                                    <span class="fi-sc-wizard-header-step-label">
                                        {{ $stepMeta['label'] }}
                                    </span>
                                    <span class="fi-sc-wizard-header-step-description">
                                        {{ $stepMeta['description'] }}
                                    </span>
                                </div>
                            </button>

                            @if (! $loop->last)
                                <div class="fi-sc-wizard-header-step-separator" aria-hidden="true"></div>
                            @endif
                        </div>
                    @endforeach
                </nav>
            </div>
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
                </div>

                @if ($this->isCompleted())
                    @php($shipComplete = $this->shipCompleteCopy())
                    <div class="{{ $this->shipCompletePanelClass() }}">
                        <div class="text-lg font-semibold">{{ $shipComplete['title'] }}</div>
                        <p class="text-sm">
                            {{ $shipComplete['body'] }}
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
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3">
                                <span class="font-semibold">{{ $this->lastScanMessage }}</span>
                                @if ($this->lastScanDetail)
                                    <x-copyable-identifier :value="$this->lastScanDetail" title="Copy identifier">
                                        @if ($this->lastScanHref)
                                            <a href="{{ $this->lastScanHref }}" class="tp-trace-link font-mono text-sm">{{ $this->lastScanDetail }}</a>
                                        @else
                                            <span class="font-mono text-sm">{{ $this->lastScanDetail }}</span>
                                        @endif
                                    </x-copyable-identifier>
                                @endif
                                @if ($this->lastScanContextLinks !== [])
                                    <span class="text-xs opacity-80">
                                        {!! \App\Support\Tracing\EpcContextLinks::renderHtml($this->lastScanContextLinks) !!}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($this->canScan())
                        <form
                            wire:submit.prevent="stageScan"
                            x-data
                            x-init="$nextTick(() => $refs.scanInput?.focus())"
                            x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                            class="flex flex-col gap-4"
                        >
                            <div class="form-control w-full gap-1.5">
                                <label for="scan-input" class="label-text text-sm font-medium">
                                    Scan barcode
                                </label>
                                <div class="flex w-full items-stretch gap-2">
                                    <input
                                        id="scan-input"
                                        type="text"
                                        wire:model.live.blur="scan"
                                        x-ref="scanInput"
                                        x-on:keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"
                                        autocomplete="off"
                                        class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                        placeholder="Scan SSCC or SGTIN"
                                    />
                                    <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                        ADD
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div class="flex flex-wrap gap-2">
                            {{ ($this->addFromReceivedAction)([]) }}
                            <button type="button" wire:click="goToStep(2)" class="btn btn-outline btn-sm">
                                Next: Customer →
                            </button>
                        </div>
                    @endif
                @elseif ($this->wizardStep === 2)
                    <div class="flex flex-col gap-4">
                        <div class="form-control w-full gap-1.5">
                            <label for="customer-search" class="label-text text-sm font-medium">Customer</label>
                            <div class="relative" wire:click.away="$set('customerDropdownOpen', false)">
                                <div class="flex gap-2">
                                    <input
                                        id="customer-search"
                                        type="search"
                                        autocomplete="off"
                                        wire:model.live.debounce.300ms="customerSearch"
                                        wire:focus="openCustomerDropdown"
                                        class="input input-bordered w-full"
                                        placeholder="Search company or ship-to address…"
                                    />
                                    @if ($this->ship_to_site_id || filled($this->customerSearch))
                                        <button
                                            type="button"
                                            wire:click="clearShipToCustomer"
                                            class="btn btn-ghost btn-sm shrink-0"
                                            title="Clear customer"
                                        >
                                            Clear
                                        </button>
                                    @endif
                                </div>

                                @if ($this->customerDropdownOpen && $this->customerSuggestions !== [])
                                    <ul
                                        class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-base-300 bg-base-100 py-1 shadow-lg"
                                        role="listbox"
                                    >
                                        @foreach ($this->customerSuggestions as $suggestion)
                                            <li role="option">
                                                <button
                                                    type="button"
                                                    wire:click="selectShipToCustomer({{ (int) $suggestion['site_id'] }})"
                                                    class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left hover:bg-base-200"
                                                >
                                                    <span class="text-sm font-medium">{{ $suggestion['company'] }}</span>
                                                    <span class="text-xs text-base-content/70">{{ $suggestion['address'] }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            @if ($summary = $this->selectedShipToSummary())
                                <p class="text-xs text-base-content/70">
                                    Ship-to: {{ $summary['address'] }}
                                </p>
                            @endif
                        </div>

                        <input type="hidden" wire:model="ship_to_gln" />

                        <div class="form-control w-full gap-1.5">
                            <label for="outbound-connection" class="label-text text-sm font-medium">Outbound connection</label>
                            <select
                                id="outbound-connection"
                                wire:model="outbound_connection_id"
                                class="select select-bordered w-full"
                            >
                                <option value="">Default for partner</option>
                                @foreach ($this->outboundConnectionOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="goToStep(1)" class="btn btn-ghost btn-sm">← Scan</button>
                            <button type="button" wire:click="mountAction('saveParty')" class="btn btn-primary btn-sm">Save customer</button>
                            <button type="button" wire:click="goToStep(3)" class="btn btn-outline btn-sm">Next: Send →</button>
                        </div>
                    </div>
                @elseif ($this->wizardStep === 3)
                    <div class="flex flex-col gap-4">
                        <div class="form-control w-full gap-1.5">
                            <label for="asn-number" class="label-text text-sm font-medium">ASN number *</label>
                            <input id="asn-number" type="text" wire:model.live.debounce.300ms="asn_number" class="input input-bordered w-full" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="form-control w-full gap-1.5">
                                <label for="customer-po" class="label-text text-sm font-medium">Customer PO *</label>
                                <input id="customer-po" type="text" wire:model.live.debounce.300ms="customer_po" class="input input-bordered w-full" />
                            </div>
                            <div class="form-control w-full gap-1.5">
                                <label for="invoice-number" class="label-text text-sm font-medium">Invoice number *</label>
                                <input id="invoice-number" type="text" wire:model.live.debounce.300ms="invoice_number" class="input input-bordered w-full" />
                            </div>
                        </div>
                        <p class="text-xs text-base-content/70">ASN is required. Also enter a customer PO or invoice (either one).</p>
                        <div class="form-control w-full gap-1.5">
                            <label for="shipment-reference" class="label-text text-sm font-medium">Shipment reference</label>
                            <input id="shipment-reference" type="text" wire:model.live.debounce.300ms="shipment_reference" class="input input-bordered w-full" />
                        </div>
                        <div class="form-control w-full gap-1.5">
                            <label for="dscsa-affirm" class="label cursor-pointer justify-start gap-3">
                                <input
                                    id="dscsa-affirm"
                                    type="checkbox"
                                    wire:model.live="dscsa_affirm"
                                    class="checkbox checkbox-primary"
                                />
                                <span class="label-text">I affirm TI/TS (DSCSA transaction statement) *</span>
                            </label>
                            @unless ($this->dscsa_affirm)
                                <p class="text-xs text-error">
                                    Required before this shipment can be sent. The affirmation is authored into the
                                    shipping EPCIS as the seller's transaction statement.
                                </p>
                            @endunless
                        </div>

                        @if ($this->atpOutboundGateDisabled())
                            <p class="text-xs text-warning" data-testid="atp-outbound-gate-disabled-send-note">
                                ATP outbound gate is disabled — this send will not verify the destination's
                                ATP license. You are affirming the customer is authorized.
                            </p>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="goToStep(2)" class="btn btn-ghost btn-sm">← Customer</button>
                            <button type="button" wire:click="mountAction('saveReferences')" class="btn btn-primary btn-sm">Save references</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
