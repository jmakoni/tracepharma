@props([
    'value' => null,
    'title' => 'Copy',
])

@php
    $copyValue = filled($value) && (string) $value !== '—' ? (string) $value : '';
@endphp

<span {{ $attributes->class(['tp-identifier-trace group inline-flex items-center gap-1']) }}>
    @if ($slot->isNotEmpty())
        {{ $slot }}
    @elseif ($copyValue !== '')
        <span class="font-mono">{{ $copyValue }}</span>
    @else
        <span>—</span>
    @endif
    @if ($copyValue !== '')
        <button
            type="button"
            x-data
            x-on:click.prevent.stop="window.navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($copyValue) }}); $tooltip('Copied', { theme: $store.theme, timeout: 2000 })"
            class="tp-copy-btn inline-flex shrink-0 items-center justify-center bg-transparent p-0.5 text-gray-500 opacity-0 transition-opacity hover:text-gray-700 focus-visible:opacity-100 group-hover:opacity-100 dark:text-gray-400 dark:hover:text-gray-200"
            title="{{ $title }}"
        >
            <x-filament::icon icon="heroicon-o-clipboard" class="h-3.5 w-3.5" />
        </button>
    @endif
</span>
