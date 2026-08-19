<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Primary actions</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if ($actions === [])
                <p class="text-sm opacity-70">No admin actions are available for this role.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($actions as $action)
                        <a
                            href="{{ $action['url'] }}"
                            class="btn {{ $action['primary'] ? 'btn-primary' : 'btn-outline' }}"
                        >
                            {{ $action['label'] }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
