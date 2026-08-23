<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($this->issuedUrl)
            <div class="alert alert-info">
                <div class="flex flex-col gap-1">
                    <span class="font-semibold">Signed customer portal URL</span>
                    <span class="font-mono text-sm break-all">{{ $this->issuedUrl }}</span>
                </div>
            </div>
        @endif

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Active trading partners</h2>
                @forelse ($this->partners() as $partner)
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-base-300 px-3 py-3">
                        <div>
                            <div class="font-semibold">{{ $partner->name }}</div>
                            <div class="text-sm opacity-70 font-mono">{{ $partner->gln }}</div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-primary min-h-12"
                            wire:click="mountAction('issueLink', { partner: {{ (int) $partner->getKey() }} })"
                        >
                            Issue link
                        </button>
                    </div>
                @empty
                    <p class="text-sm opacity-70">No active trading partners.</p>
                @endforelse
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
