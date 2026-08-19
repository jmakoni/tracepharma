@props([
    'accent' => false,
    'padding' => true,
])

<div {{ $attributes->class([
    'tp-card',
    'tp-card-accent' => $accent,
    'p-5' => $padding,
]) }}>
    {{ $slot }}
</div>
