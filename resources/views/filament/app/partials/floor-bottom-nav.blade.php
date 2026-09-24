@php
    use App\Filament\App\Pages\FloorFind;
    use App\Support\Floor\FloorRouteMap;

    $floorUrl = FloorRouteMap::launcherUrl();
    $receiptsUrl = FloorRouteMap::receiveListFloorUrl() ?? $floorUrl;
    $findUrl = FloorFind::canAccess()
        ? FloorFind::getUrl(panel: 'app')
        : FloorRouteMap::launcherUrl();
    $active = $active ?? 'floor';
    $logoutUrl = filament()->getLogoutUrl();
@endphp

<div class="dock dock-md tp-floor-bottom-nav z-50 pb-[env(safe-area-inset-bottom)]" aria-label="Floor navigation">
    <a
        href="{{ $floorUrl }}"
        wire:navigate
        @class(['dock-active' => $active === 'floor'])
    >
        <x-filament::icon icon="heroicon-o-home" class="size-6" />
        <span class="dock-label">Floor</span>
    </a>
    <a
        href="{{ $receiptsUrl }}"
        wire:navigate
        @class(['dock-active' => $active === 'receipts'])
    >
        <x-filament::icon icon="heroicon-o-inbox-arrow-down" class="size-6" />
        <span class="dock-label">Receipts</span>
    </a>
    <button
        type="button"
        @class(['dock-active' => $active === 'site'])
        @click="$dispatch('tp-floor-site-open')"
    >
        <x-filament::icon icon="heroicon-o-building-office-2" class="size-6" />
        <span class="dock-label">Site</span>
    </button>
    <div
        class="relative flex h-full max-w-32 flex-1 basis-full flex-col items-center justify-center"
        x-data="{ open: false }"
        @keydown.escape.window="open = false"
    >
        <button
            type="button"
            @class([
                'dock-active' => $active === 'profile',
                'flex h-full w-full flex-col items-center justify-center gap-px',
            ])
            @click="open = !open"
            :aria-expanded="open"
        >
            <x-filament::icon icon="heroicon-o-user-circle" class="size-6" />
            <span class="dock-label">Profile</span>
        </button>
        <div
            x-show="open"
            x-cloak
            class="absolute bottom-full right-0 z-50 mb-2 min-w-40 rounded-box border border-base-300 bg-base-100 p-2 shadow-lg"
            @click.outside="open = false"
        >
            <a
                href="{{ $findUrl }}"
                wire:navigate
                class="btn btn-ghost btn-sm w-full justify-start font-normal"
            >Find</a>
            <a
                href="{{ url('/') }}"
                class="tp-floor-layout-toggle btn btn-ghost btn-sm w-full justify-start font-normal"
                onclick="document.cookie='{{ \App\Support\Floor\FloorLayout::COOKIE }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
            >Desktop view</a>
            <form method="POST" action="{{ $logoutUrl }}" class="mt-1">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm w-full justify-start font-normal">
                    Log out
                </button>
            </form>
        </div>
    </div>
</div>

@include('filament.app.partials.floor-site-sheet')
