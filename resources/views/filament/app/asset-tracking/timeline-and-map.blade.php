@php
    $points = $this->trace['map_points'] ?? [];
    $timeline = $this->trace['timeline'] ?? [];
    $mapKey = $this->trace['urn'] ?? $this->trace['primary_identifier'] ?? 'none';
    $hasMap = $points !== [];
    // One card per disposition + business step (latest event_time wins).
    // Multi-key sortByMany requires two-arg comparators (not value extractors).
    $timelineTime = static fn (array $step): int => filled($step['event_time'] ?? null)
        ? (int) \Illuminate\Support\Carbon::parse($step['event_time'])->getTimestampMs()
        : 0;
    $timelineId = static fn (array $step): int => (int) ($step['id'] ?? 0);
    $dedupedTimeline = collect($timeline)
        ->groupBy(fn (array $step): string => sprintf(
            '%s|%s',
            filled($step['disposition'] ?? null) ? $step['disposition'] : 'No disposition',
            filled($step['business_step'] ?? null) ? $step['business_step'] : 'Event',
        ))
        ->map(fn ($steps) => $steps
            ->sortBy([
                fn (array $a, array $b) => $timelineTime($b) <=> $timelineTime($a),
                fn (array $a, array $b) => $timelineId($b) <=> $timelineId($a),
            ])
            ->first())
        ->values()
        ->sortBy([
            fn (array $a, array $b) => $timelineTime($a) <=> $timelineTime($b),
            fn (array $a, array $b) => $timelineId($a) <=> $timelineId($b),
        ])
        ->values();
@endphp

<div data-tp-tracking-grid>

    <div data-tp-tracking-map>
        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide opacity-60">Map</h3>

        @if ($hasMap)
            <div
                wire:key="asset-tracking-map-{{ $mapKey }}"
                wire:ignore
                x-data="tpAssetTrackingMap(@js($points))"
                data-tp-tracking-map-panel
                class="overflow-hidden rounded-lg border border-base-200"
            >
                <div x-ref="mapEl" class="h-full w-full"></div>
            </div>
        @else
            <div
                data-tp-tracking-map-panel
                class="flex items-center justify-center rounded-lg border border-base-200 bg-base-100 px-4 text-center text-sm opacity-60"
            >
                No mapped locations for this asset yet.
            </div>
        @endif
    </div>

    <div data-tp-tracking-timeline>
        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide opacity-60">Timeline</h3>

        @if (empty($timeline))
            <p class="text-sm opacity-60">No EPCIS events recorded yet.</p>
        @else
            <div data-tp-timeline-cards>

                @foreach ($dedupedTimeline as $step)
                    @php
                        $bizStepColor = \App\Support\Tracing\CbvStatusColor::businessStep($step['business_step'] ?? null);
                        $dispColor = \App\Support\Tracing\CbvStatusColor::disposition($step['disposition'] ?? null);
                        $actionColor = \App\Support\Tracing\CbvStatusColor::action($step['action'] ?? null);
                        $eventTime = filled($step['event_time'] ?? null)
                            ? \Illuminate\Support\Carbon::parse($step['event_time'])->format('Y-m-d H:i:sP')
                            : null;
                    @endphp
                    <div data-tp-timeline-card>
                        @if (! empty($step['inferred']))
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-sm badge-ghost">Implied</span>
                                @if (filled($step['inferred_from'] ?? null))
                                    <span class="text-xs opacity-60">via {{ $step['inferred_from'] }}</span>
                                @endif
                            </div>
                        @endif
                        <div data-tp-timeline-field>
                            <span data-tp-timeline-label>Business Step</span>
                            <span @class([
                                'badge badge-sm',
                                \App\Support\Tracing\CbvStatusColor::daisyBadgeClass($bizStepColor),
                            ])>
                                {{ $step['business_step'] ?? 'Event' }}
                            </span>
                        </div>
                        <div data-tp-timeline-field>
                            <span data-tp-timeline-label>Disposition</span>
                            @if (filled($step['disposition'] ?? null))
                                <span @class([
                                    'badge badge-sm',
                                    \App\Support\Tracing\CbvStatusColor::daisyBadgeClass($dispColor),
                                ])>
                                    {{ $step['disposition'] }}
                                </span>
                            @else
                                <span class="text-sm opacity-60">—</span>
                            @endif
                        </div>
                        <div data-tp-timeline-field>
                            <span data-tp-timeline-label>Event Time</span>
                            <span class="font-mono text-sm">{{ $eventTime ?? '—' }}</span>
                        </div>
                        <div data-tp-timeline-field>
                            <span data-tp-timeline-label>Action</span>
                            @if (filled($step['action'] ?? null))
                                <span @class([
                                    'badge badge-sm uppercase tracking-wide',
                                    \App\Support\Tracing\CbvStatusColor::daisyBadgeClass($actionColor),
                                ])>
                                    {{ $step['action'] }}
                                </span>
                            @else
                                <span class="text-sm opacity-60">—</span>
                            @endif
                        </div>
                        <div data-tp-timeline-field>
                            <span data-tp-timeline-label>Site</span>
                            <span class="text-sm">{{ $step['site'] ?? '—' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
