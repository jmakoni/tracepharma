<x-filament-panels::page>
    @php
        $widgets = $this->widgets();
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
            </div>
        </div>

        @forelse ($widgets as $widget)
            <section class="card bg-base-100 shadow-xl" wire:key="admin-analytics-{{ $widget['key'] }}">
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

                    @include('filament.admin.pages.partials.analytics.'.$widget['key'], ['data' => $widget['data']])
                </div>
            </section>
        @empty
            <div class="alert">
                <span>No analytics widgets are available for this admin role.</span>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
