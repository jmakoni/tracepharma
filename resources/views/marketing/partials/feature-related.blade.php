@php
    $links = [
        'verification' => ['label' => 'Verification', 'route' => route('marketing.features.show', 'verification')],
        'receiving' => ['label' => 'Receiving', 'route' => route('marketing.features.show', 'receiving')],
        'exceptions' => ['label' => 'Exceptions', 'route' => route('marketing.features.show', 'exceptions')],
        'compliance' => ['label' => 'Compliance', 'route' => route('marketing.features.show', 'compliance')],
        'integrations' => ['label' => 'Integrations', 'route' => route('marketing.features.show', 'integrations')],
        'serialization' => ['label' => 'Serialization', 'route' => route('marketing.features.show', 'serialization')],
    ];
    unset($links[$current]);
@endphp

<section class="border-t border-tp-border bg-tp-canvas">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
        <h2 class="text-lg font-semibold text-tp-ink">Explore other capabilities</h2>
        <div class="mt-6 flex flex-wrap gap-3">
            @foreach ($links as $link)
                <a
                    href="{{ $link['route'] }}"
                    class="rounded-full border border-tp-border bg-tp-surface px-4 py-2 text-sm font-medium text-tp-muted transition hover:border-tp-teal-500/40 hover:text-tp-link"
                >
                    {{ $link['label'] }}
                </a>
            @endforeach
            <a
                href="{{ route('marketing.features') }}"
                class="rounded-full border border-tp-border bg-tp-surface px-4 py-2 text-sm font-medium text-tp-muted transition hover:border-tp-teal-500/40 hover:text-tp-link"
            >
                All features
            </a>
        </div>
    </div>
</section>