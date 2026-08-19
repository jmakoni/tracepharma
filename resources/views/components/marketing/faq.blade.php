@props([
    'items',
    'title' => 'Frequently asked questions',
])

<section {{ $attributes->merge(['class' => 'border-t border-tp-border']) }}>
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">{{ $title }}</h2>
        <dl class="mt-8 space-y-4">
            @foreach ($items as $item)
                <div class="tp-card p-6">
                    <dt class="text-base font-semibold text-tp-ink">{{ $item['question'] }}</dt>
                    <dd class="mt-3 text-sm leading-relaxed text-tp-muted">{{ $item['answer'] }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>
