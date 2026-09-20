@php
    $mode = $mode ?? 'desktop';
    $phoneMax = \App\Support\Floor\FloorLayout::PHONE_MAX_PX;
    $desktopMin = \App\Support\Floor\FloorLayout::DESKTOP_MIN_PX;
    $cookieName = \App\Support\Floor\FloorLayout::COOKIE;
@endphp

{{-- Auto layout redirect: phone always floor; tablet respects cookie; desktop never auto-floor. --}}
<div
    wire:ignore
    x-data
    x-init="
        const cookie = document.cookie.split('; ').find((r) => r.startsWith(@js($cookieName) + '='))?.split('=')[1] ?? null;
        const w = window.innerWidth;
        const mode = @js($mode);
        const floorUrl = @js($floorUrl);
        const desktopUrl = @js($desktopUrl);
        const phoneMax = {{ $phoneMax }};
        const desktopMin = {{ $desktopMin }};
        const withSearch = (url) => {
            const search = window.location.search;
            return search ? url + search : url;
        };
        const navigateIfDifferent = (url) => {
            const target = withSearch(url);
            try {
                const resolved = new URL(target, window.location.origin);
                if (
                    window.location.pathname !== resolved.pathname
                    || window.location.search !== resolved.search
                ) {
                    window.location.replace(resolved.href);
                }
            } catch (e) {
                if (window.location.href !== target) {
                    window.location.replace(target);
                }
            }
        };
        if (mode === 'desktop') {
            // Phone: always floor (ignore desktop cookie).
            if (w < phoneMax) {
                navigateIfDifferent(floorUrl);
            } else if (w < desktopMin && cookie !== 'desktop') {
                navigateIfDifferent(floorUrl);
            }
        } else if (mode === 'floor') {
            // Never leave floor on phone. Desktop width leaves unless cookie forces floor.
            if (w >= desktopMin && cookie !== 'floor') {
                navigateIfDifferent(desktopUrl);
            }
        }
    "
    class="hidden"
    data-tp-floor-layout-switch="1"
    aria-hidden="true"
></div>

{{-- Tablet-only visible toggle (phone/desktop hide via CSS). --}}
<div class="tp-floor-layout-toggle flex flex-wrap items-center gap-2 text-sm">
    @if ($mode === 'floor')
        <a
            href="{{ $desktopUrl }}"
            class="link link-hover opacity-80"
            onclick="document.cookie='{{ $cookieName }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
        >Desktop view</a>
    @else
        <a
            href="{{ $floorUrl }}"
            class="link link-hover opacity-80"
            onclick="document.cookie='{{ $cookieName }}=floor;path=/;max-age=31536000;SameSite=Lax'"
        >Floor view</a>
    @endif
</div>
