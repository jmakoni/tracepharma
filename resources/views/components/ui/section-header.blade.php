@props([
    'title',
    'description' => null,
    'size' => 'base',
    'eyebrow' => null,
])

<div {{ $attributes->class(['flex flex-wrap items-start justify-between gap-3']) }}>
    <div>
        @if ($eyebrow)
            <p class="text-sm font-semibold uppercase tracking-wide text-tp-muted dark:text-tp-dark-muted">{{ $eyebrow }}</p>
        @endif
        <h2 @class([
            'font-semibold text-tp-ink dark:text-white',
            'text-base' => $size === 'base',
            'text-lg' => $size === 'lg',
            'mt-0' => ! $eyebrow,
            'mt-1' => $eyebrow,
        ])>{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-tp-muted dark:text-tp-dark-muted">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</div>
