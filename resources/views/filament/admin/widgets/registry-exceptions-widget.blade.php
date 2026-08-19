<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Registry exceptions</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if ($empty)
                <p class="text-sm opacity-80">No pending match reviews or unresolved unmatched facilities.</p>
            @else
                <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                    <div class="stat">
                        <div class="stat-title">Match reviews</div>
                        <div class="stat-value text-2xl">{{ $pendingMatchReviews }}</div>
                        <div class="stat-desc">Pending</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Unmatched</div>
                        <div class="stat-value text-2xl">{{ $unresolvedUnmatched }}</div>
                        <div class="stat-desc">Unresolved facilities</div>
                    </div>
                </div>
            @endif

            <div class="card-actions flex flex-wrap gap-2">
                @if ($reviewsUrl)
                    <a href="{{ $reviewsUrl }}" class="btn btn-ghost btn-sm">Match reviews</a>
                @endif
                @if ($unmatchedUrl)
                    <a href="{{ $unmatchedUrl }}" class="btn btn-ghost btn-sm">Unmatched</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
