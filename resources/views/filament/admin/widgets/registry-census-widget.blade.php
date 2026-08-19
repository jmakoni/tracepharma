<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Registry census</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if ($empty)
                <p class="text-sm opacity-80">No FDA registry records have been imported yet.</p>
            @else
                <div class="stats stats-vertical w-full shadow lg:stats-horizontal">
                    <div class="stat">
                        <div class="stat-title">Organizations</div>
                        <div class="stat-value text-2xl">{{ $organizations }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Establishments</div>
                        <div class="stat-value text-2xl">{{ $establishments }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Facilities</div>
                        <div class="stat-value text-2xl">{{ $facilities }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Licenses</div>
                        <div class="stat-value text-2xl">{{ $licenses }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Products</div>
                        <div class="stat-value text-2xl">{{ $products }}</div>
                    </div>
                </div>
            @endif

            <div class="card-actions flex-wrap">
                @if ($organizationsUrl)
                    <a href="{{ $organizationsUrl }}" class="btn btn-ghost btn-sm">Organizations</a>
                @endif
                @if ($establishmentsUrl)
                    <a href="{{ $establishmentsUrl }}" class="btn btn-ghost btn-sm">Establishments</a>
                @endif
                @if ($facilitiesUrl)
                    <a href="{{ $facilitiesUrl }}" class="btn btn-ghost btn-sm">Facilities</a>
                @endif
                @if ($licensesUrl)
                    <a href="{{ $licensesUrl }}" class="btn btn-ghost btn-sm">Licenses</a>
                @endif
                @if ($productsUrl)
                    <a href="{{ $productsUrl }}" class="btn btn-ghost btn-sm">Products</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
