@props([
    'eyebrow' => null,
    'title',
    'description' => null,
])

<section class="border-b border-tp-border">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        @if (isset($breadcrumb))
            <div class="mb-6 text-sm text-tp-muted [&_a]:hover:text-tp-link">
                {!! $breadcrumb !!}
            </div>
        @endif
        @if ($eyebrow)
            <p class="text-sm font-semibold uppercase tracking-wide text-tp-teal-400">{{ $eyebrow }}</p>
        @endif
        <h1 class="tp-display mt-3 max-w-3xl text-4xl font-medium tracking-tight text-tp-ink">{{ $title }}</h1>
        @if ($description)
            <p class="mt-4 max-w-3xl text-lg leading-relaxed text-tp-muted">{{ $description }}</p>
        @endif
        @if (isset($actions))
            <div class="mt-6 flex flex-wrap gap-4 [&_a]:text-sm [&_a]:font-semibold [&_a]:text-tp-link [&_a]:hover:text-tp-primary-600 dark:[&_a]:hover:text-tp-primary-200">
                {!! $actions !!}
            </div>
        @endif
    </div>
</section>