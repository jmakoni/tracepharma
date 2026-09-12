@props([])

<x-scan-flash />

<div {{ $attributes->class(['flex flex-col gap-4']) }}>
    {{ $header ?? '' }}

    {{ $slot }}
</div>
