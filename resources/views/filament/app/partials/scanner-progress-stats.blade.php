@props([
    'stats' => [],
    'ariaLabel' => null,
    'class' => 'stats stats-vertical sm:stats-horizontal bg-base-200 shadow',
])

@php
    /** @var list<array{title: string, value: string|int, desc?: string|null}> $stats */
    $stats = array_values(array_filter(
        $stats,
        static fn (mixed $stat): bool => is_array($stat) && isset($stat['title'], $stat['value']),
    ));

    $aria = $ariaLabel;
    if ($aria === null && $stats !== []) {
        $aria = collect($stats)
            ->map(static fn (array $stat): string => $stat['title'].' '.$stat['value'])
            ->implode(' · ');
    }
@endphp

@if ($stats !== [])
    <div
        {{ $attributes->class([$class]) }}
        @if (filled($aria))
            aria-label="{{ $aria }}"
        @endif
        aria-live="polite"
    >
        @foreach ($stats as $stat)
            <div class="stat">
                <div class="stat-title">{{ $stat['title'] }}</div>
                <div class="stat-value text-2xl">{{ $stat['value'] }}</div>
                @if (filled($stat['desc'] ?? null))
                    <div class="stat-desc">{{ $stat['desc'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
@endif
