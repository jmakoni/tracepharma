<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <label class="form-control gap-1 max-w-xl">
                    <span class="label-text text-sm font-medium">Site</span>
                    <select wire:model.live="siteId" class="select select-bordered">
                        @foreach ($this->siteOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($this->isTruncated())
                    <p class="text-sm text-warning">Showing the first 400 hits. More matching serials are on hand at this site.</p>
                @endif

                @forelse ($this->rows() as $epc)
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-base-300 px-3 py-3">
                        <div>
                            <div class="font-mono text-sm font-semibold">{{ $this->identifier($epc) }}</div>
                            <div class="text-sm opacity-70">
                                Lot {{ $epc->ilmd?->lot_number ?? '—' }}
                                · GTIN {{ $epc->gtin14 ?? '—' }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($this->canQuarantine())
                                <button
                                    type="button"
                                    class="btn btn-error min-h-12"
                                    wire:click="mountAction('quarantineHit', { epc: {{ (int) $epc->getKey() }} })"
                                >
                                    Quarantine
                                </button>
                            @endif
                            <button
                                type="button"
                                class="btn btn-ghost min-h-12"
                                wire:click="mountAction('markAccounted', { epc: {{ (int) $epc->getKey() }} })"
                            >
                                Mark accounted
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm opacity-70">No open-recall hits on hand at this site.</p>
                @endforelse
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
