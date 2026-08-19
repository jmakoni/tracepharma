@props([
    'contextLabel' => null,
    'contextSub' => null,
    'withFooterPadding' => false,
])

<div @class([
    'tp-scanner-shell space-y-4',
    'scanner-page-shell' => $withFooterPadding,
])>
    @if ($contextLabel)
        <div class="rounded-box border-2 border-primary/40 bg-primary/10 px-4 py-3" role="status">
            @if ($contextSub)
                <p class="text-xs font-semibold uppercase tracking-wide opacity-70">{{ $contextSub }}</p>
            @endif
            <p class="truncate text-xl font-bold">{{ $contextLabel }}</p>
        </div>
    @endif
    {{ $slot }}
</div>
