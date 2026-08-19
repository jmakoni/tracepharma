@props([
    'title',
    'items',
])

<div {{ $attributes->merge(['class' => 'tp-card p-6 sm:p-8']) }}>
    <h2 class="text-lg font-semibold text-tp-ink">{{ $title }}</h2>
    <ul class="mt-5 space-y-3">
        @foreach ($items as $item)
            <li class="flex gap-3 text-sm leading-relaxed text-tp-muted">
                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-tp-accent-500/30 bg-tp-accent-500/10 text-xs font-semibold text-tp-teal-400">✓</span>
                <span>{{ $item }}</span>
            </li>
        @endforeach
    </ul>
</div>