@props([
    'variant' => 'primary',
])

@php
    $variantClass = match ($variant) {
        'success' => 'tp-status--success',
        'warning' => 'tp-status--warning',
        'danger' => 'tp-status--danger',
        default => 'tp-status--primary',
    };
@endphp

<span {{ $attributes->class(['tp-status', $variantClass]) }}>
    {{ $slot }}
</span>
