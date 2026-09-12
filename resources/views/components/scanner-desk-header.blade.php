@props([])

@php
    $hasContext = isset($context) && ! $context->isEmpty();
    $hasQty = isset($qty) && ! $qty->isEmpty();
    $hasAlert = isset($alert) && ! $alert->isEmpty();
    $hasScan = isset($scan) && ! $scan->isEmpty();
    $hasMeta = isset($meta) && ! $meta->isEmpty();
@endphp

<div
    x-data="{ flashTone: null }"
    x-on:scan-result.window="
        flashTone = $event.detail.tone;
        setTimeout(() => { flashTone = null }, 700)
    "
    :class="{
        'ring-4 ring-success/40': flashTone === 'ok',
        'ring-4 ring-warning/40': flashTone === 'warn',
        'ring-4 ring-error/40': flashTone === 'error',
    }"
    {{ $attributes->class(['card bg-base-100 shadow-xl transition-shadow sticky top-0 z-10']) }}
>
    <div class="card-body gap-4">
        @if ($hasContext || $hasQty)
            <div class="flex flex-wrap items-center justify-between gap-2">
                @if ($hasContext)
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        {{ $context }}
                    </div>
                @endif
                @if ($hasQty)
                    {{ $qty }}
                @endif
            </div>
        @endif

        @if ($hasAlert)
            {{ $alert }}
        @endif

        @if ($hasScan)
            {{ $scan }}
        @endif

        @if ($hasMeta)
            {{ $meta }}
        @endif

        {{ $slot }}
    </div>
</div>
