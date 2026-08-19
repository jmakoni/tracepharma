@props([
    'title',
    'description',
    'href' => null,
])

@php
    $classes = 'tp-card block p-6 transition hover:border-tp-teal-300 hover:bg-tp-canvas';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes . ' group']) }}>
        <h3 class="text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $title }}</h3>
        <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $description }}</p>
        <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Learn more →</span>
    </a>
@else
    <div {{ $attributes->merge(['class' => $classes]) }}>
        <h3 class="text-lg font-semibold text-tp-ink">{{ $title }}</h3>
        <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $description }}</p>
    </div>
@endif