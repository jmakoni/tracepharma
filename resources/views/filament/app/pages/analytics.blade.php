<x-filament-panels::page>
    @php
        $widgets = $this->widgets();
        $sites = $this->eligibleSites();
        $partners = $this->activePartners();
    @endphp

    <div class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title text-base">Filters</h2>
                        <p class="text-sm opacity-70">
                            As of {{ $this->asOfLabel() }}
                        </p>
                    </div>
                    <div class="join">
                        <button
                            type="button"
                            wire:click="$set('rangeDays', 7)"
                            class="btn join-item btn-sm {{ $rangeDays === 7 ? 'btn-primary' : 'btn-ghost' }}"
                        >
                            7 days
                        </button>
                        <button
                            type="button"
                            wire:click="$set('rangeDays', 30)"
                            class="btn join-item btn-sm {{ $rangeDays === 30 ? 'btn-primary' : 'btn-ghost' }}"
                        >
                            30 days
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label class="form-control w-full">
                        <span class="label-text text-sm font-medium">Site</span>
                        <select
                            wire:model.live.debounce.500ms="siteId"
                            class="select select-bordered w-full"
                        >
                            <option value="">All eligible sites</option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}">{{ $site->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="form-control w-full">
                        <span class="label-text text-sm font-medium">Trading partner</span>
                        <select
                            wire:model.live.debounce.500ms="tradingPartnerId"
                            class="select select-bordered w-full"
                        >
                            <option value="">All active partners</option>
                            @foreach ($partners as $partner)
                                <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>
        </div>

        @forelse ($widgets as $widget)
            <section class="card bg-base-100 shadow-xl" wire:key="analytics-{{ $widget['key'] }}">
                <div class="card-body gap-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="card-title text-base">{{ $widget['label'] }}</h2>
                            <p class="text-sm opacity-70">{{ $widget['description'] }}</p>
                        </div>
                        @if (filled($widget['url']))
                            <a href="{{ $widget['url'] }}" class="btn btn-ghost btn-sm">
                                {{ $widget['url_label'] ?? 'Open' }}
                            </a>
                        @endif
                    </div>

                    @include('filament.app.pages.partials.analytics.'.$widget['key'], ['data' => $widget['data']])
                </div>
            </section>
        @empty
            <div class="alert">
                <span>No analytics widgets are available for this organization or role.</span>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
