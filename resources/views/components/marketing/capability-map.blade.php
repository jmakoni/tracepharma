@props([
    'links',
])

<nav aria-label="DSCSA workflow deep dives" {{ $attributes }}>
    <ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        @foreach ($links as $link)
            <li>
                <a href="{{ $link['href'] }}" class="tp-capability-map__link group">
                    <span class="font-mono text-[10px] font-medium uppercase tracking-[0.18em] text-tp-teal-400">{{ $link['phase'] }}</span>
                    <span class="mt-2 block text-base font-semibold text-tp-ink group-hover:text-tp-link">{{ $link['title'] }}</span>
                    <span class="mt-1 block text-xs leading-relaxed text-tp-muted">{{ $link['description'] }}</span>
                </a>
            </li>
        @endforeach
    </ol>
</nav>