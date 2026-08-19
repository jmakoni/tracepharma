<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Compliance pulse</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                @if ($showExceptions)
                    <div class="stat">
                        <div class="stat-title">Open exceptions</div>
                        <div class="stat-value text-2xl">{{ $openExceptions }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-title">Quarantine holds</div>
                        <div class="stat-value text-2xl">{{ $openHolds }}</div>
                    </div>
                @endif
            </div>

            @if ($showTracing)
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-medium">Tracing at risk</h3>
                        @if ($tracingUrl)
                            <a href="{{ $tracingUrl }}" class="btn btn-ghost btn-xs">All requests</a>
                        @endif
                    </div>
                    @if ($tracingAtRisk === [])
                        <p class="text-sm opacity-70">No overdue or due-soon tracing requests.</p>
                    @else
                        <ul class="menu bg-base-200 rounded-box w-full">
                            @foreach ($tracingAtRisk as $request)
                                <li>
                                    <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <div class="font-medium">{{ $request['title'] }}</div>
                                            <div class="text-sm font-normal opacity-70">
                                                @if ($request['overdue'])
                                                    <span class="badge badge-error badge-outline badge-sm">Overdue</span>
                                                @else
                                                    <span class="badge badge-warning badge-outline badge-sm">Due soon</span>
                                                @endif
                                                @if ($request['dueLabel'])
                                                    · {{ $request['dueLabel'] }}
                                                @endif
                                            </div>
                                        </div>
                                        @if ($request['url'])
                                            <a href="{{ $request['url'] }}" class="btn btn-ghost btn-sm">Open</a>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="card-actions flex flex-wrap gap-2">
                @if ($exceptionsUrl)
                    <a href="{{ $exceptionsUrl }}" class="btn btn-ghost btn-sm">Exceptions</a>
                @endif
                @if ($quarantineUrl)
                    <a href="{{ $quarantineUrl }}" class="btn btn-ghost btn-sm">Quarantine</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
