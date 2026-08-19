<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Onboarding queue</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if ($empty)
                <p class="text-sm opacity-80">No submitted or approved onboardings, and no demo requests in the last 7 days.</p>
            @else
                <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                    <div class="stat">
                        <div class="stat-title">Submitted</div>
                        <div class="stat-value text-2xl">{{ $submitted }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Approved</div>
                        <div class="stat-value text-2xl">{{ $approved }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Demos</div>
                        <div class="stat-value text-2xl">{{ $demoRequests }}</div>
                        <div class="stat-desc">Last 7 days</div>
                    </div>
                </div>
            @endif

            <div class="card-actions flex flex-wrap gap-2">
                @if ($onboardingUrl)
                    <a href="{{ $onboardingUrl }}" class="btn btn-ghost btn-sm">Customer onboarding</a>
                @endif
                @if ($demoUrl)
                    <a href="{{ $demoUrl }}" class="btn btn-ghost btn-sm">Demo requests</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
