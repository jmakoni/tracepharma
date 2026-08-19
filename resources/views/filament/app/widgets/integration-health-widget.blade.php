<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Integration health</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }} · last 24 hours</p>
            </div>

            <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                @if ($showInbound)
                    <div class="stat">
                        <div class="stat-title">Inbound errors</div>
                        <div class="stat-value text-2xl">{{ $inboundErrors }}</div>
                    </div>
                @endif
                @if ($showOutbound)
                    <div class="stat">
                        <div class="stat-title">Outbound failed</div>
                        <div class="stat-value text-2xl">{{ $outboundFailed }}</div>
                    </div>
                @endif
            </div>

            <div class="card-actions">
                @if ($healthUrl)
                    <a href="{{ $healthUrl }}" class="btn btn-ghost btn-sm">Integration health</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
