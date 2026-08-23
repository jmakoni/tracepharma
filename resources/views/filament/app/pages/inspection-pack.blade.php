<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl max-w-xl">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70">
                Downloads ATP licenses, open exceptions, open quarantine holds, and the latest FDA 3911 PDF when one exists.
            </p>
            <label class="form-control gap-1">
                <span class="label-text text-sm font-medium">Site (ATP filter)</span>
                <select wire:model.live="siteId" class="select select-bordered">
                    @foreach ($this->siteOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button type="button" class="btn btn-primary min-h-12" wire:click="mountAction('downloadPack')">
                Download pack
            </button>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
