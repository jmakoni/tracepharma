<div class="min-h-dvh bg-base-200 pb-20">
    @include('filament.app.partials.floor-layout-switch-auto', [
        'mode' => 'floor',
        'desktopUrl' => $this->desktopLauncherUrl(),
        'floorUrl' => $this->floorLauncherUrl(),
    ])

    @if (session(\App\Http\Middleware\RedirectUnmappedFloorShell::FLASH_KEY))
        <div class="alert alert-warning mx-4 mt-3 text-sm" role="status">
            Use desktop for that page. Floor is for handheld tasks.
        </div>
    @endif

    <header class="px-4 pt-4">
        <div class="mb-4 flex items-center justify-between gap-3">
            <img
                src="{{ filament()->getFavicon() }}"
                alt="TracePharma"
                class="h-8 w-8 shrink-0"
            />
            <button
                type="button"
                class="badge badge-ghost shrink-0 font-mono text-sm"
                @click="$dispatch('tp-floor-site-open')"
            >{{ $this->currentSiteCode() }}</button>
        </div>
        <h1 class="text-3xl font-semibold text-base-content">Floor</h1>
    </header>

    <div class="mt-4 grid grid-cols-2 gap-3 px-4">
        @foreach ($this->launcherTiles() as $tile)
            <a
                href="{{ $tile['url'] }}"
                wire:navigate
                data-tile="{{ $tile['key'] }}"
                class="btn h-28 flex-col gap-2 rounded-2xl border border-base-300 bg-base-100 font-normal shadow-sm"
            >
                <x-filament::icon
                    :icon="$this->launcherTileIcon($tile['key'])"
                    class="size-8 shrink-0 opacity-80"
                />
                <span class="text-sm font-medium">{{ $tile['label'] }}</span>
            </a>
        @endforeach
    </div>

    @include('filament.app.partials.floor-bottom-nav', ['active' => 'floor'])
</div>
