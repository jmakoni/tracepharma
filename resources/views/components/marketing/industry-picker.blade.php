@props([
    'industries',
])

<nav aria-label="Solutions by industry" {{ $attributes }}>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($industries as $industry)
            @if (! empty($industry['href']))
                <a
                    href="{{ $industry['href'] }}"
                    @class([
                        'tp-card group block p-6 transition hover:border-tp-accent-500/30',
                        'border-tp-accent-500/40 ring-1 ring-tp-accent-500/20' => ! empty($industry['highlight']),
                    ])
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">{{ $industry['label'] }}</p>
                    <h3 class="mt-2 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $industry['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $industry['description'] }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Explore →</span>
                </a>
            @else
                <div class="tp-card p-6 opacity-80">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-muted">{{ $industry['label'] }}</p>
                    <h3 class="mt-2 text-lg font-semibold text-tp-ink">{{ $industry['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $industry['description'] }}</p>
                </div>
            @endif
        @endforeach
    </div>
</nav>