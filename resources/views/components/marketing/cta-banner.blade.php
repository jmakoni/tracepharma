@props([
    'title',
    'description' => null,
    'buttonText' => 'Request a demo',
    'buttonHref' => null,
    'footnote' => null,
])

<section {{ $attributes->merge(['class' => 'border-t border-tp-border bg-gradient-to-r from-tp-accent-50 to-tp-purple-100']) }}>
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-semibold tracking-tight text-tp-ink sm:text-3xl">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-3 text-base leading-relaxed text-tp-muted">{{ $description }}</p>
                @endif
                @if ($footnote)
                    <p class="mt-2 text-xs text-tp-muted">{{ $footnote }}</p>
                @endif
            </div>
            <a href="{{ $buttonHref ?? route('marketing.demo') }}" class="tp-btn-primary shrink-0">
                {{ $buttonText }}
            </a>
        </div>
    </div>
</section>