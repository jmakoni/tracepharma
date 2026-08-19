<x-filament-panels::page>
    <div class="alert alert-warning shadow-sm">
        <div>
            <h2 class="font-semibold">Suspect product SOP</h2>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm opacity-90">
                <li>Isolate the physical product from sellable inventory immediately.</li>
                <li>Do not dispense or ship until the investigation is resolved or cleared.</li>
                <li>Document the serial / lot on the linked exception.</li>
                <li>Contact the manufacturer or wholesaler when verification failed or a recall is active.</li>
            </ol>
        </div>
    </div>

    <section class="mt-8">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">Open holds</h2>
                <p class="mt-1 text-sm opacity-70">QuarantineHold records still open. Release only after QA clears the hold.</p>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div class="form-control w-full max-w-xs">
                    <label class="label py-0" for="quarantine-site">
                        <span class="label-text text-xs">Site</span>
                    </label>
                    <select
                        id="quarantine-site"
                        wire:model.live="siteId"
                        class="select select-bordered select-sm w-full"
                    >
                        <option value="">All accessible sites</option>
                        @foreach ($this->siteFilterOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-control w-full max-w-xs">
                    <label class="label py-0" for="quarantine-filter">
                        <span class="label-text text-xs">Filter</span>
                    </label>
                    <input
                        id="quarantine-filter"
                        type="search"
                        wire:model.live.debounce.500ms="filter"
                        class="input input-bordered input-sm w-full"
                        placeholder="GTIN, serial, reason…"
                    />
                </div>
            </div>
        </div>

        @php
            $holds = $this->openHolds();
            $holdsTotal = $this->openHoldsTotal();
            $holdsLastPage = $this->holdsLastPage();
        @endphp

        @if ($holds->isEmpty())
            <p class="mt-4 text-sm opacity-70">
                No open quarantine holds{{ filled($this->filter) || filled($this->siteId) ? ' match this filter' : '' }}.
                @if (filled($this->siteId))
                    Document-less and find-recall holds only appear when All accessible sites is selected.
                @endif
            </p>
        @else
            <div class="mt-4 space-y-2">
                @foreach ($holds as $hold)
                    <article class="card bg-base-100 shadow-sm border border-warning/30">
                        <div class="card-body gap-2 p-4">
                            <div class="flex flex-wrap items-center gap-2 text-sm font-mono">
                                @if ($hold->epc?->gtin14)
                                    <span class="badge badge-outline">GTIN {{ $hold->epc->gtin14 }}</span>
                                @endif
                                @if ($hold->epc?->serial_number)
                                    <span class="badge badge-outline">SERIAL {{ $hold->epc->serial_number }}</span>
                                @endif
                                <span class="badge badge-warning">{{ $hold->severity }}</span>
                            </div>
                            <p class="text-sm">{{ $hold->reason }}</p>
                            <p class="text-xs opacity-60">
                                Opened {{ $hold->opened_at?->toDateTimeString() ?? '—' }}
                                @if ($hold->exception)
                                    · Exception: {{ $hold->exception->title }}
                                @endif
                            </p>
                            <div class="card-actions mt-1 flex flex-wrap gap-2">
                                @if ($this->canReleaseHold($hold))
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        color="success"
                                        icon="heroicon-o-check-circle"
                                        wire:click="mountAction('releaseHold', { hold: {{ $hold->id }} })"
                                    >
                                        Release hold
                                    </x-filament::button>
                                @endif
                                @if ($hold->exception_id)
                                    <x-filament::button
                                        tag="a"
                                        :href="$this->exceptionUrl((int) $hold->exception_id)"
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-o-eye"
                                    >
                                        View exception
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs opacity-60">
                <p>
                    Showing {{ $holds->count() }} of {{ $holdsTotal }}
                    @if ($holdsLastPage > 1)
                        · Page {{ $this->holdsPage }} of {{ $holdsLastPage }}
                    @endif
                </p>
                @if ($holdsLastPage > 1)
                    <div class="flex gap-2">
                        <x-filament::button
                            type="button"
                            size="xs"
                            color="gray"
                            wire:click="previousHoldsPage"
                            :disabled="$this->holdsPage <= 1"
                        >
                            Previous
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            size="xs"
                            color="gray"
                            wire:click="nextHoldsPage"
                            :disabled="$this->holdsPage >= $holdsLastPage"
                        >
                            Next
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif
    </section>

    <section class="mt-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">Open investigations</h2>
                <p class="mt-1 text-sm opacity-70">Exception cases with open quarantine holds — resolve via the exception workflow.</p>
            </div>
            <x-filament::button tag="a" :href="$this->exceptionsUrl()" size="sm" color="danger" icon="heroicon-o-exclamation-triangle">
                All exceptions
            </x-filament::button>
        </div>

        @php
            $investigations = $this->openInvestigations();
            $investigationsTotal = $this->openInvestigationsTotal();
            $investigationsLastPage = $this->investigationsLastPage();
        @endphp

        @if ($investigations->isEmpty())
            <p class="mt-4 text-sm opacity-70">
                No open investigations with quarantine holds{{ filled($this->siteId) ? '' : '.' }}
                @if (filled($this->siteId))
                    . The site filter may hide document-less cases — try All accessible sites.
                @endif
            </p>
        @else
            <div class="mt-4 space-y-2">
                @foreach ($investigations as $exception)
                    <article class="card bg-base-100 shadow-sm border border-error/30">
                        <div class="card-body gap-2 p-4">
                            <p class="font-medium">{{ $exception->title }}</p>
                            <p class="text-sm opacity-70">{{ $exception->description }}</p>
                            <p class="text-xs opacity-60">
                                {{ $exception->status?->label() ?? $exception->status?->value }}
                                @if ($exception->type)
                                    · {{ $exception->type->name ?? $exception->type->code }}
                                @endif
                            </p>
                            <div class="card-actions">
                                <x-filament::button
                                    tag="a"
                                    :href="$this->exceptionUrl((int) $exception->id)"
                                    size="sm"
                                    color="danger"
                                    icon="heroicon-o-magnifying-glass"
                                >
                                    Investigate exception
                                </x-filament::button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs opacity-60">
                <p>
                    Showing {{ $investigations->count() }} of {{ $investigationsTotal }}
                    @if ($investigationsLastPage > 1)
                        · Page {{ $this->investigationsPage }} of {{ $investigationsLastPage }}
                    @endif
                </p>
                @if ($investigationsLastPage > 1)
                    <div class="flex gap-2">
                        <x-filament::button
                            type="button"
                            size="xs"
                            color="gray"
                            wire:click="previousInvestigationsPage"
                            :disabled="$this->investigationsPage <= 1"
                        >
                            Previous
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            size="xs"
                            color="gray"
                            wire:click="nextInvestigationsPage"
                            :disabled="$this->investigationsPage >= $investigationsLastPage"
                        >
                            Next
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif
    </section>
</x-filament-panels::page>
