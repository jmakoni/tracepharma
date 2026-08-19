<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Import health</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if ($empty)
                <p class="text-sm opacity-80">No incomplete, failed, or partial FDA import runs.</p>
            @else
                <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                    <div class="stat">
                        <div class="stat-title">Incomplete</div>
                        <div class="stat-value text-2xl">{{ $incomplete }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Failed</div>
                        <div class="stat-value text-2xl">{{ $failed }}</div>
                        <div class="stat-desc">Latest per source</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Partial</div>
                        <div class="stat-value text-2xl">{{ $partial }}</div>
                        <div class="stat-desc">Latest per source</div>
                    </div>
                </div>
            @endif

            <div class="card-actions">
                @if ($runsUrl)
                    <a href="{{ $runsUrl }}" class="btn btn-ghost btn-sm">Import runs</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
