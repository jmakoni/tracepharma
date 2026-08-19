@php
    use Filament\Support\Icons\Heroicon;

    $options = \App\Support\Auth\CurrentSite::options();
    $siteId = \App\Support\Auth\CurrentSite::id();
    $showSwitcher = count($options) > 1;
    $currentLabel = $siteId !== null
        ? ($options[$siteId] ?? $options[(string) $siteId] ?? \App\Models\Site::query()->find($siteId)?->name)
        : null;
@endphp

@if(filled($currentLabel))
    <div class="tp-site-switcher flex items-center">
        <x-filament::dropdown
            placement="bottom-end"
            :teleport="true"
            :flip="false"
            width="tp-site-menu-panel"
            class="fi-site-menu"
        >
            <x-slot name="trigger">
                <x-filament::icon-button
                    :icon="Heroicon::OutlinedBuildingOffice2"
                    color="gray"
                    size="xl"
                    :label="'Current site: '.$currentLabel"
                    :tooltip="$currentLabel"
                    class="fi-site-menu-trigger"
                />
            </x-slot>

            <x-filament::dropdown.header :icon="Heroicon::OutlinedBuildingOffice2">
                {{ $currentLabel }}
            </x-filament::dropdown.header>

            @if ($showSwitcher)
                <x-filament::dropdown.list>
                    @foreach ($options as $id => $label)
                        @php $isCurrent = (int) $id === (int) $siteId; @endphp
                        <x-filament::dropdown.list.item
                            tag="form"
                            method="post"
                            class="w-full min-w-0"
                            :action="route('tenant.current-site.set', ['site' => (int) $id])"
                            :icon="$isCurrent ? Heroicon::Check : null"
                            :color="$isCurrent ? 'primary' : 'gray'"
                        >
                            {{ $label }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            @endif
        </x-filament::dropdown>
    </div>
@endif
