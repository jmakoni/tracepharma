@php
    use App\Support\Floor\FloorLayout;
    use App\Support\Floor\FloorRouteMap;

    $twin = FloorRouteMap::twinForPath();
    $isFloor = FloorRouteMap::isFloorPath();
    $launcherUrl = FloorRouteMap::launcherUrl();
    $phoneMax = FloorLayout::PHONE_MAX_PX;
    $desktopMin = FloorLayout::DESKTOP_MIN_PX;
    $cookieName = FloorLayout::COOKIE;
@endphp

@if ($twin !== null)
    {{-- Mapped desktop URL: reuse the single Alpine detector. --}}
    @include('filament.app.partials.floor-layout-switch', [
        'mode' => 'desktop',
        'desktopUrl' => $twin['desktopUrl'],
        'floorUrl' => $twin['floorUrl'],
    ])
@elseif (! $isFloor && ! request()->routeIs('filament.app.auth.login'))
    {{-- Unmapped desktop URL: soft interstitial on handheld. --}}
    <div
        wire:ignore
        x-data="{ show: false }"
        x-init="
            const cookie = document.cookie.split('; ').find((r) => r.startsWith(@js($cookieName) + '='))?.split('=')[1] ?? null;
            const w = window.innerWidth;
            const phoneMax = {{ $phoneMax }};
            const desktopMin = {{ $desktopMin }};
            show = w < phoneMax || (w < desktopMin && cookie !== 'desktop');
        "
        x-show="show"
        x-cloak
        class="tp-floor-desktop-interstitial"
        data-tp-floor-interstitial="1"
        role="status"
    >
        <div class="tp-floor-desktop-interstitial__inner">
            <p class="tp-floor-desktop-interstitial__title">Use desktop for this page</p>
            <p class="tp-floor-desktop-interstitial__body">
                This screen is for desktop. On a tablet, turn on Desktop view from Floor → Profile.
                Or open Floor for handheld tasks.
            </p>
            <a href="{{ $launcherUrl }}" class="tp-floor-desktop-interstitial__cta" wire:navigate>Open Floor</a>
        </div>
    </div>
@endif
