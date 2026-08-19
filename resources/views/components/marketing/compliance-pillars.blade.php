@props([
    'pillars',
])

<div {{ $attributes->class(['grid gap-6 md:grid-cols-2 lg:grid-cols-3']) }}>
    @foreach ($pillars as $pillar)
        <div class="tp-card border-l-2 border-tp-accent-500/50 p-6 pl-5">
            <h3 class="text-base font-semibold text-tp-ink">{{ $pillar['title'] }}</h3>
            <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $pillar['description'] }}</p>
            @if (! empty($pillar['items']))
                <ul class="mt-4 space-y-2 text-sm text-tp-muted">
                    @foreach ($pillar['items'] as $item)
                        <li class="flex gap-2">
                            <span class="text-tp-teal-400">→</span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>