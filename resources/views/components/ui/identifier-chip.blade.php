@props([
    'label',
    'value',
])

<span {{ $attributes->class(['tp-chip']) }}>
    <span class="tp-chip-label">{{ $label }}</span>{{ $value }}
</span>