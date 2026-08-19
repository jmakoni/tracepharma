<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Tenant census</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if ($empty)
                <p class="text-sm opacity-80">No tenants have been provisioned yet.</p>
            @else
                <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                    <div class="stat">
                        <div class="stat-title">Total</div>
                        <div class="stat-value text-2xl">{{ $total }}</div>
                    </div>
                    @foreach ($byStatus as $status)
                        <div class="stat">
                            <div class="stat-title">{{ $status['label'] }}</div>
                            <div class="stat-value text-2xl">{{ $status['count'] }}</div>
                        </div>
                    @endforeach
                </div>

                <ul class="flex flex-wrap gap-2">
                    @foreach ($byProfile as $profile)
                        <li class="badge badge-outline">{{ $profile['label'] }} · {{ $profile['count'] }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="card-actions">
                @if ($tenantsUrl)
                    <a href="{{ $tenantsUrl }}" class="btn btn-ghost btn-sm">Tenants</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
