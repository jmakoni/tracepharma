@php
    /** @var list<array{key: string, label: string, icon: ?string, color: ?string, active: bool, is_preset: bool}> $items */
@endphp

<div class="tp-table-views">
    <div class="tp-table-views-bar" role="tablist" aria-label="Table views">
        @foreach ($items as $item)
            <button
                type="button"
                role="tab"
                wire:click="applyTableView({{ \Illuminate\Support\Js::from($item['key']) }})"
                aria-selected="{{ $item['active'] ? 'true' : 'false' }}"
                class="tp-table-views-chip tp-table-views-chip--{{ $item['color'] ?? 'primary' }} {{ $item['active'] ? 'is-active' : '' }}"
            >
                @if (filled($item['icon']))
                    <x-filament::icon :icon="$item['icon']" class="tp-table-views-chip-icon" />
                @endif
                <span>{{ $item['label'] }}</span>
            </button>
        @endforeach

        <div class="tp-table-views-actions">
            <button
                type="button"
                wire:click="quickSaveTableView"
                class="tp-table-views-action"
            >
                <x-filament::icon icon="heroicon-o-bookmark" class="tp-table-views-chip-icon" />
                <span>{{ $canQuickUpdate ? 'Update view' : 'Quick Save' }}</span>
            </button>
            <button
                type="button"
                wire:click="openTableViewManager"
                class="tp-table-views-action"
            >
                <x-filament::icon icon="heroicon-o-cog-6-tooth" class="tp-table-views-chip-icon" />
                <span>Manage</span>
            </button>
        </div>
    </div>

    @include('filament-table-views::components.view-manager')
</div>
