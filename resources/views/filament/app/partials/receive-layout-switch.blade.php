@props([
    'mode' => 'desktop', // desktop | floor — the page currently rendered
    'desktopUrl',
    'floorUrl',
])

@php
    $breakpoint = \App\Support\Receiving\ReceiveLayout::BREAKPOINT_PX;
    $cookieName = \App\Support\Receiving\ReceiveLayout::COOKIE;
@endphp

<div
    wire:ignore
    x-data
    x-init="
        const cookie = document.cookie.split('; ').find((r) => r.startsWith(@js($cookieName) + '='))?.split('=')[1] ?? null;
        const w = window.innerWidth;
        const mode = @js($mode);
        const floorUrl = @js($floorUrl);
        const desktopUrl = @js($desktopUrl);
        const withSearch = (url) => {
            const search = window.location.search;
            return search ? url + search : url;
        };
        if (mode === 'desktop') {
            if (cookie !== 'desktop' && w < {{ $breakpoint }}) {
                window.location.replace(withSearch(floorUrl));
            }
        } else if (mode === 'floor') {
            if (cookie !== 'floor' && w >= {{ $breakpoint }}) {
                window.location.replace(withSearch(desktopUrl));
            }
        }
    "
    class="hidden"
    aria-hidden="true"
></div>

<div class="flex flex-wrap items-center gap-2 text-sm">
    @if ($mode === 'floor')
        <a
            href="{{ $desktopUrl }}"
            class="link link-hover opacity-80"
            onclick="document.cookie='{{ $cookieName }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
        >Use desktop view</a>
    @else
        <a
            href="{{ $floorUrl }}"
            class="link link-hover opacity-80"
            onclick="document.cookie='{{ $cookieName }}=floor;path=/;max-age=31536000;SameSite=Lax'"
        >Use floor view</a>
    @endif
</div>
