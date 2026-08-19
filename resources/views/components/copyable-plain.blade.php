@props([
    'value' => null,
    'title' => 'Copy',
])

@php
    $copyValue = filled($value) && (string) $value !== '—' ? (string) $value : '';
@endphp

<span {{ $attributes->class(['mono']) }}>
    @if ($slot->isNotEmpty())
        {{ $slot }}
    @else
        {{ $copyValue !== '' ? $copyValue : '—' }}
    @endif
    @if ($copyValue !== '')
        <button
            type="button"
            class="copy-id"
            title="{{ $title }}"
            onclick="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($copyValue) }}); this.setAttribute('title', 'Copied'); setTimeout(() => this.setAttribute('title', {{ \Illuminate\Support\Js::from($title) }}), 1500)"
        >⎘</button>
    @endif
</span>
