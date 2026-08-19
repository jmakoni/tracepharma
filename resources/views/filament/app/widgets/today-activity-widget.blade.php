<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Today’s activity</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }} · last 24 hours</p>
            </div>

            <div class="stats stats-vertical lg:stats-horizontal w-full shadow">
                @if ($showReceive)
                    <div class="stat">
                        <div class="stat-title">Received</div>
                        <div class="stat-value text-2xl">{{ $receivesCompleted }}</div>
                        <div class="stat-desc">Completed sessions</div>
                    </div>
                @endif
                @if ($showShip)
                    <div class="stat">
                        <div class="stat-title">Shipped</div>
                        <div class="stat-value text-2xl">{{ $shipsCompleted }}</div>
                        <div class="stat-desc">Completed sessions</div>
                    </div>
                @endif
                @if ($showExceptions)
                    <div class="stat">
                        <div class="stat-title">Exceptions</div>
                        <div class="stat-value text-2xl">{{ $exceptionsOpened }}</div>
                        <div class="stat-desc">Opened</div>
                    </div>
                @endif
                @if ($showVrs && $vrsAllowed !== null && $vrsBlocked !== null)
                    <div class="stat">
                        <div class="stat-title">VRS</div>
                        <div class="stat-value text-2xl">{{ $vrsAllowed }} / {{ $vrsBlocked }}</div>
                        <div class="stat-desc">Allowed / blocked</div>
                    </div>
                @endif
            </div>

            <div class="card-actions flex flex-wrap gap-2">
                @if ($receiveUrl)
                    <a href="{{ $receiveUrl }}" class="btn btn-ghost btn-sm">Receive</a>
                @endif
                @if ($shipUrl)
                    <a href="{{ $shipUrl }}" class="btn btn-ghost btn-sm">Ship</a>
                @endif
                @if ($exceptionsUrl)
                    <a href="{{ $exceptionsUrl }}" class="btn btn-ghost btn-sm">Exceptions</a>
                @endif
                @if ($verifyUrl)
                    <a href="{{ $verifyUrl }}" class="btn btn-ghost btn-sm">Verify</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
