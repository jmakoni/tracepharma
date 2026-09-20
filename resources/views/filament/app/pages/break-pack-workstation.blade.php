<x-filament-panels::page>
    <x-scanner-desk>
        <x-slot:header>
            <x-scanner-desk-header>
                <x-slot:context>
                    <span class="badge badge-lg badge-outline font-semibold">
                        Site: {{ $this->commissionSiteLabel() }}
                    </span>
                </x-slot:context>

                <x-slot:qty>
                    @if ($this->parentEpcId && $this->openChildren !== [])
                        @include('filament.app.partials.scanner-progress-stats', [
                            'stats' => [
                                [
                                    'title' => 'Selected',
                                    'value' => count(array_values(array_unique(array_map('intval', $this->selectedChildIds)))),
                                ],
                                [
                                    'title' => 'Open',
                                    'value' => count($this->openChildren),
                                ],
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
                            <span class="font-semibold">{{ $this->lastMessage }}</span>
                        </div>
                    @endif
                </x-slot:alert>

                <x-slot:scan>
                    <form
                        wire:submit.prevent="processScan"
                        x-data
                        x-init="$nextTick(() => $refs.scanInput?.focus())"
                        x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                        x-on:keydown.enter.prevent="$wire.processScan()"
                        class="flex flex-col gap-3"
                    >
                        <div class="flex w-full flex-col gap-3">
                            <label for="break-pack-scan-input" class="text-sm font-medium">
                                Scan source parent (or child to toggle)
                            </label>
                            <div class="flex w-full items-stretch gap-2">
                                <input
                                    id="break-pack-scan-input"
                                    type="text"
                                    wire:model.live.blur="scan"
                                    x-ref="scanInput"
                                    autocomplete="off"
                                    class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                    placeholder="Scan source SSCC / child"
                                />
                                <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                    Scan
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
                    </div>
                </x-slot:meta>
            </x-scanner-desk-header>
        </x-slot:header>

        @if ($this->parentEpcId)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="card-title text-base">Source parent</h2>
                            <p class="font-mono text-sm">{{ $this->parentLabel }}</p>
                            @if ($this->sourceDocumentId)
                                <p class="text-xs opacity-70">Inbound document #{{ $this->sourceDocumentId }}</p>
                            @endif
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="clearParent">
                            Clear
                        </button>
                    </div>

                    @if ($this->openChildren === [])
                        <p class="text-sm opacity-70">No open children under this parent.</p>
                    @else
                        @php($selectedRows = $this->selectedScanRows())
                        @if ($selectedRows !== [])
                            <x-scanner-confirmed-table
                                :rows="$selectedRows"
                                :title="'Selected ('.count($selectedRows).')'"
                                empty="No children selected."
                                remove-method="deselectChild"
                                id-key="epc_id"
                            />
                        @endif

                        <fieldset class="flex flex-col gap-2">
                            <legend class="text-sm font-medium">Children to break &amp; pack</legend>
                            @foreach ($this->openChildren as $childId => $label)
                                <label class="label cursor-pointer justify-start gap-3 min-h-12 rounded-lg border border-base-300 px-3">
                                    <input
                                        type="checkbox"
                                        class="checkbox"
                                        value="{{ $childId }}"
                                        wire:model.live="selectedChildIds"
                                    />
                                    <span class="label-text font-mono text-sm">{{ $label }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="btn btn-primary min-h-14"
                wire:click="mountAction('confirmBreakPack')"
                wire:loading.attr="disabled"
                @disabled($this->selectedChildIds === [])
            >
                Confirm break & pack
            </button>
        </div>
    </x-scanner-desk>

    <x-filament-actions::modals />
</x-filament-panels::page>
