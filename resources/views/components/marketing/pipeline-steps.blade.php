@props([
    'steps',
])

<div {{ $attributes->class(['flex flex-col gap-3 lg:flex-row lg:items-stretch lg:gap-2']) }} role="list">
    @foreach ($steps as $index => $step)
        @if ($index > 0)
            <span class="tp-pipeline-connector" aria-hidden="true">→</span>
        @endif
        <div class="tp-pipeline-step" role="listitem">
            <span class="font-mono text-[10px] font-medium uppercase tracking-[0.18em] text-tp-teal-400">{{ $step['phase'] }}</span>
            <span class="mt-2 block font-semibold text-tp-ink">{{ $step['title'] }}</span>
            <span class="mt-1 block text-sm leading-relaxed text-tp-muted">{{ $step['description'] }}</span>
        </div>
    @endforeach
</div>