<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <h2 class="card-title text-base">Hub health</h2>
                <p class="text-sm opacity-70">As of {{ $asOf }}</p>
            </div>

            @if ($aggregationLinkFkDriftCount > 0)
                <div class="alert alert-warning">
                    <span>
                        {{ $aggregationLinkFkDriftCount }} tenant(s) still use CASCADE on
                        <code>aggregation_links.established_by_event_id</code>.
                        @if ($aggregationLinkFkCheckedAt)
                            Last doctor run: {{ $aggregationLinkFkCheckedAt }}.
                        @endif
                        Run <code>php artisan tracepharma:doctor-aggregation-link-fk --fix</code> after review.
                    </span>
                </div>
            @endif

            @if ($empty)
                <p class="text-sm opacity-80">No hub environments or active routes are configured.</p>
            @else
                <div class="stats stats-vertical sm:stats-horizontal w-full shadow">
                    <div class="stat">
                        <div class="stat-title">Active routes</div>
                        <div class="stat-value text-2xl">{{ $activeRoutes }}</div>
                    </div>
                </div>

                <ul class="flex flex-wrap gap-2">
                    @foreach ($environments as $environment)
                        <li class="badge {{ $environment['ok'] ? 'badge-success' : 'badge-warning' }} badge-outline">
                            {{ $environment['label'] }}
                            · {{ $environment['ok'] ? 'OK' : 'Needs setup' }}
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="card-actions">
                @if ($hubUrl)
                    <a href="{{ $hubUrl }}" class="btn btn-ghost btn-sm">EPCIS Hub</a>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
