<x-filament-panels::page>
    @include('filament.app.partials.floor-layout-switch', [
        'mode' => 'desktop',
        'desktopUrl' => \App\Filament\App\Pages\PackWorkstation::getUrl(panel: 'app'),
        'floorUrl' => \App\Filament\App\Pages\MobilePackWorkstation::getUrl(panel: 'app'),
    ])

    <x-scanner-desk>
        <x-slot:header>
            <x-scanner-desk-header>
                <x-slot:context>
                    <span class="badge badge-lg badge-outline font-semibold">
                        Site: {{ $this->commissionSiteLabel() }}
                    </span>
                </x-slot:context>

                <x-slot:qty>
                    @if ($this->children !== [])
                        @include('filament.app.partials.scanner-progress-stats', [
                            'stats' => [
                                ['title' => 'Children', 'value' => count($this->children)],
                            ],
                            'class' => 'stats stats-horizontal bg-base-200 shadow',
                        ])
                    @endif
                </x-slot:qty>

                <x-slot:alert>
                    @if ($this->lastMessage)
                        <div
                            role="status"
                            aria-live="{{ $this->lastTone === 'error' ? 'assertive' : 'polite' }}"
                            @class([
                                'alert',
                                'alert-success' => $this->lastTone === 'ok',
                                'alert-warning' => $this->lastTone === 'warn',
                                'alert-error' => $this->lastTone === 'error',
                            ])
                        >
                            <div class="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <span class="font-semibold">{{ $this->lastMessage }}</span>
                                @if ($this->batchUrl)
                                    <a href="{{ $this->batchUrl }}" class="btn btn-sm btn-outline">Open batch</a>
                                @endif
                            </div>
                        </div>
                    @endif
                </x-slot:alert>

                <x-slot:scan>
                    <form
                        x-on:submit.prevent="$wire.processScan($refs.scanInput.value)"
                        x-data
                        x-init="$nextTick(() => $refs.scanInput?.focus())"
                        x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                        x-on:keydown.enter.prevent="$wire.processScan($refs.scanInput.value)"
                        class="flex flex-col gap-3"
                    >
                        <div class="flex w-full flex-col gap-3">
                            <label for="pack-scan-input" class="text-sm font-medium">
                                {{ $this->parentLabelId ? 'Scan child EPC' : 'Scan child EPC or generated SSCC' }}
                            </label>
                            <div class="flex w-full items-stretch gap-2">
                                <input
                                    id="pack-scan-input"
                                    type="text"
                                    wire:model="scan"
                                    x-ref="scanInput"
                                    autocomplete="off"
                                    class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                    placeholder="{{ $this->parentLabelId ? 'Scan SGTIN or child SSCC' : 'Scan child or generated parent SSCC' }}"
                                />
                                <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                    Add
                                </button>
                            </div>
                        </div>
                    </form>
                </x-slot:scan>

                <x-slot:meta>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="form-control gap-1">
                            <span class="label-text text-sm font-medium">Organization</span>
                            <span class="text-sm">{{ $this->tenantNameDisplay() }}</span>
                        </label>
                        <label class="form-control gap-1">
                            <span class="label-text text-sm font-medium">GS1 Company Prefix</span>
                            <span class="font-mono text-sm">{{ $this->companyPrefixDisplay() }}</span>
                        </label>
                        <p class="label-text text-sm opacity-70 sm:col-span-2">
                            Break a case on Unpack, then pack mixed bottles here. Aggregation EPCIS is always emitted.
                        </p>
                    </div>
                </x-slot:meta>
            </x-scanner-desk-header>
        </x-slot:header>

        @if ($this->parentLabelId)
            @php($summary = $this->packContentSummary())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="card-title text-base">Bound parent SSCC</h2>
                            <p class="font-mono text-sm">{{ $this->parentSscc18 }}</p>
                        </div>
                        <span class="badge badge-outline">
                            {{ $this->boundParentChildCount() }} already packed
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <span class="badge badge-ghost">{{ $summary['lot_count'] }} lots</span>
                        <span class="badge badge-ghost">{{ $summary['gtin_count'] }} GTINs</span>
                        @if ($summary['is_mixed'])
                            <span class="badge badge-warning">Mixed logistics unit — SSCC only</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        <x-scanner-confirmed-table
            :rows="$this->children"
            :title="'Children to pack ('.count($this->children).')'"
            empty="Scan children to build the pack list."
            remove-method="removeChild"
            id-key="epc_id"
        >
            <x-slot:headerActions>
                @if ($this->children !== [])
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="clearChildren">
                        Clear list
                    </button>
                @endif
            </x-slot:headerActions>
        </x-scanner-confirmed-table>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="btn btn-primary min-h-14"
                wire:click="mountAction('confirmPack')"
                wire:loading.attr="disabled"
                @disabled($this->children === [])
            >
                {{ $this->parentLabelId ? 'Add to SSCC' : 'Confirm pack' }}
            </button>
        </div>
    </x-scanner-desk>

    <x-filament-actions::modals />
</x-filament-panels::page>
