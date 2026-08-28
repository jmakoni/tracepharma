<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label class="form-control gap-1 flex-1">
                        <span class="label-text text-sm font-medium">Site</span>
                        <select wire:model.live="siteId" class="select select-bordered">
                            @foreach ($this->siteOptions() as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="form-control gap-1">
                        <span class="label-text text-sm font-medium">Window</span>
                        <select wire:model.live="windowDays" class="select select-bordered">
                            <option value="30">30 days</option>
                            <option value="60">60 days</option>
                            <option value="90">90 days</option>
                        </select>
                    </label>
                </div>

                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Identifier</th>
                                <th>Lot</th>
                                <th>Expiry</th>
                                <th>Days</th>
                                @if ($this->canQuarantine())
                                    <th></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows() as $epc)
                                <tr>
                                    <td class="font-mono text-sm">{{ $this->identifier($epc) }}</td>
                                    <td>{{ $epc->ilmd?->lot_number ?? '—' }}</td>
                                    <td>{{ $epc->ilmd?->expiry_date?->toDateString() ?? '—' }}</td>
                                    <td>{{ $this->daysLeft($epc) ?? '—' }}</td>
                                    @if ($this->canQuarantine())
                                        <td class="text-right">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-error btn-outline"
                                                wire:click="mountAction('quarantineHit', { epc: {{ (int) $epc->getKey() }} })"
                                            >
                                                Quarantine
                                            </button>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $this->canQuarantine() ? 5 : 4 }}" class="text-sm opacity-70">No on-hand serials expire in this window.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
