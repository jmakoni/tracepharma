@props([
    'items',
])

<div {{ $attributes->class(['tp-card overflow-hidden']) }}>
    <ul class="tp-feature-list">
        @foreach ($items as $item)
            <li class="px-5 py-4 sm:px-6 sm:py-5">
                <h3 class="font-semibold text-tp-ink">{{ $item['title'] }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-tp-muted">{{ $item['description'] }}</p>
            </li>
        @endforeach
    </ul>
</div>