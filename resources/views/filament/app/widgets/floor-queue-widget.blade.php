<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Floor queue</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if (! $siteSelected)
                <div class="alert">
                    <span>Select a site to see open receive and ship sessions.</span>
                </div>
            @elseif ($empty)
                <div class="flex flex-col gap-3">
                    <p class="text-sm opacity-80">No open receive or ship sessions at this site.</p>
                    @if ($hubUrl)
                        <a href="{{ $hubUrl }}" class="btn btn-primary btn-sm w-fit">Go to Operations Hub</a>
                    @endif
                </div>
            @else
                <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                    <div class="stat">
                        <div class="stat-title">Receive</div>
                        <div class="stat-value text-2xl">{{ $receivingOpen }}</div>
                        <div class="stat-desc">Open or in progress</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Ship</div>
                        <div class="stat-value text-2xl">{{ $shippingOpen }}</div>
                        <div class="stat-desc">Open or in progress</div>
                    </div>
                </div>
            @endif

            <div class="card-actions flex flex-wrap gap-2">
                @if ($receiveUrl)
                    <a href="{{ $receiveUrl }}" class="btn btn-ghost btn-sm">Receive list</a>
                @endif
                @if ($shipUrl)
                    <a href="{{ $shipUrl }}" class="btn btn-ghost btn-sm">Ship list</a>
                @endif
                @if ($hubUrl)
                    <a href="{{ $hubUrl }}" class="btn btn-ghost btn-sm">Operations Hub</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
