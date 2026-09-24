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
                    @if ($this->parentEpcId && $this->openChildrenCount() > 0)
                        @include('filament.app.partials.scanner-progress-stats', [
                            'stats' => [
                                ['title' => 'Selected', 'value' => $this->selectedCount()],
                                ['title' => 'Open', 'value' => $this->openChildrenCount()],
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
                                @if ($this->showPostUnpackHandoff)
                                    <div class="flex flex-wrap gap-2">
                                        @if ($this->packWorkstationUrl())
                                            <a href="{{ $this->packWorkstationUrl() }}" class="btn btn-sm btn-outline">Open Pack</a>
                                        @endif
                                        @if ($this->unpackedItemsUrl())
                                            <a href="{{ $this->unpackedItemsUrl() }}" class="btn btn-sm btn-outline">Unpacked items</a>
                                        @endif
                                    </div>
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
                            <label for="unpack-scan-input" class="text-sm font-medium">
                                @if ($this->parentEpcId)
                                    Scan a child to toggle (or parent to confirm)
                                @else
                                    Scan an SSCC or case to break (or a child to load parent)
                                @endif
                            </label>
                            <div class="flex w-full items-stretch gap-2">
                                <input
                                    id="unpack-scan-input"
                                    type="text"
                                    wire:model="scan"
                                    x-ref="scanInput"
                                    autocomplete="off"
                                    class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                    placeholder="Scan SSCC / case / child"
                                />
                                <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                    Scan
                                </button>
                            </div>
                        </div>
                    </form>
                </x-slot:scan>
            </x-scanner-desk-header>
        </x-slot:header>

        @unless ($this->parentEpcId)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-3 text-sm">
                    <h2 class="card-title text-base">How unpack works</h2>
                    <ol class="list-decimal list-inside space-y-1 opacity-80">
                        <li>Scan parent SSCC or case (or scan a child to load its parent).</li>
                        <li>Select children into “Selected to unpack” (tap or scan), then confirm.</li>
                        <li>Confirm unpack — then pack loose items on Pack if needed.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2 pt-1">
                        @if ($this->packWorkstationUrl())
                            <a href="{{ $this->packWorkstationUrl() }}" class="btn btn-outline btn-sm">Pack</a>
                        @endif
                        @if ($this->breakPackWorkstationUrl())
                            <a href="{{ $this->breakPackWorkstationUrl() }}" class="btn btn-ghost btn-sm">Break &amp; pack</a>
                        @endif
                        @if ($this->unpackedItemsUrl())
                            <a href="{{ $this->unpackedItemsUrl() }}" class="btn btn-ghost btn-sm">Unpacked items</a>
                        @endif
                    </div>
                </div>
            </div>
        @endunless

        @if ($this->parentEpcId)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="card-title text-base">Parent</h2>
                            <p class="font-mono text-sm">{{ $this->parentLabel }}</p>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="clearParent">
                            Clear
                        </button>
                    </div>

                    @if ($this->hiddenChildrenCount > 0)
                        <p class="text-sm text-warning">
                            {{ $this->hiddenChildrenCount }}
                            {{ $this->hiddenChildrenCount === 1 ? 'child is' : 'children are' }}
                            hidden (hold/custody) and cannot be unpacked here.
                        </p>
                    @endif

                    @if ($this->openChildren === [])
                        <p class="text-sm opacity-70">No open children under this parent.</p>
                        @if ($this->showPostUnpackHandoff || $this->hiddenChildrenCount === 0)
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="btn btn-ghost min-h-14" wire:click="clearParent">
                                    Clear parent
                                </button>
                                @if ($this->packWorkstationUrl())
                                    <a href="{{ $this->packWorkstationUrl() }}" class="btn btn-outline min-h-14">Open Pack</a>
                                @endif
                                @if ($this->unpackedItemsUrl())
                                    <a href="{{ $this->unpackedItemsUrl() }}" class="btn btn-outline min-h-14">Unpacked items</a>
                                @endif
                            </div>
                        @endif
                    @else
                        @php($selectedRows = $this->selectedScanRows())
                        @php($containerChildren = $this->containerChildren())

                        @if ($selectedRows !== [])
                            <x-scanner-confirmed-table
                                :rows="$selectedRows"
                                :title="'Selected to unpack ('.count($selectedRows).')'"
                                empty="No children selected."
                                remove-method="toggleChild"
                                id-key="epc_id"
                            />
                        @endif

                        <fieldset class="flex flex-col gap-2">
                            <legend class="text-sm font-medium">Still in container ({{ count($containerChildren) }})</legend>
                            @forelse ($containerChildren as $childId => $label)
                                <button
                                    type="button"
                                    wire:click="toggleChild({{ (int) $childId }})"
                                    class="flex min-h-12 w-full cursor-pointer items-center justify-between gap-3 rounded-lg border border-base-300 px-3 text-start"
                                >
                                    <span class="flex min-w-0 items-center gap-3">
                                        <input type="checkbox" class="checkbox pointer-events-none" tabindex="-1" aria-hidden="true">
                                        <span class="font-mono text-sm">{{ $label }}</span>
                                    </span>
                                </button>
                            @empty
                                <p class="text-sm opacity-70">All open children are selected.</p>
                            @endforelse
                        </fieldset>
                    @endif
                </div>
            </div>
        @endif
    </x-scanner-desk>

    <x-filament-actions::modals />
</x-filament-panels::page>
