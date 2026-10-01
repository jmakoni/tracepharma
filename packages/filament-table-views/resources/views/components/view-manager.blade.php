@php
    /** @var bool $tableViewSaveAsOpen */
    /** @var bool $tableViewManagerOpen */
    /** @var bool $canShare */
    /** @var int|null $editingTableViewId */
    /** @var list<string> $iconOptions */
    /** @var list<string> $colorOptions */
    /** @var list<\Tracepharma\FilamentTableViews\Models\TableView> $manageableViews */
@endphp

@if ($tableViewSaveAsOpen)
    <div class="tp-table-views-overlay" wire:click="closeTableViewSaveAs"></div>
    <aside class="tp-table-views-slideover" role="dialog" aria-label="Save table view">
        <header class="tp-table-views-slideover-header">
            <h2>Save view</h2>
            <button type="button" class="tp-table-views-icon-btn" wire:click="closeTableViewSaveAs" aria-label="Close">
                <x-filament::icon icon="heroicon-o-x-mark" class="tp-table-views-chip-icon" />
            </button>
        </header>
        <div class="tp-table-views-slideover-body">
            <label class="tp-table-views-field">
                <span>Name</span>
                <input type="text" wire:model="tableViewForm.name" maxlength="100" />
            </label>
            <label class="tp-table-views-field">
                <span>Icon</span>
                <select wire:model="tableViewForm.icon">
                    <option value="">None</option>
                    @foreach ($iconOptions as $icon)
                        <option value="{{ $icon }}">{{ str($icon)->afterLast('-')->replace('-', ' ')->title() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="tp-table-views-field">
                <span>Color</span>
                <select wire:model="tableViewForm.color">
                    @foreach ($colorOptions as $color)
                        <option value="{{ $color }}">{{ ucfirst($color) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="tp-table-views-check">
                <input type="checkbox" wire:model="tableViewForm.is_favorite" />
                <span>Favorite (show on bar)</span>
            </label>
            @if ($canShare)
                <label class="tp-table-views-check">
                    <input type="checkbox" wire:model="tableViewForm.is_public" />
                    <span>Public (this organization)</span>
                </label>
                <label class="tp-table-views-check">
                    <input type="checkbox" wire:model="tableViewForm.is_global" />
                    <span>Global favorite</span>
                </label>
            @endif
            <label class="tp-table-views-check">
                <input type="checkbox" wire:model="tableViewForm.is_default" />
                <span>Load by default</span>
            </label>
        </div>
        <footer class="tp-table-views-slideover-footer">
            <button type="button" class="tp-table-views-action" wire:click="closeTableViewSaveAs">Cancel</button>
            <button type="button" class="tp-table-views-action is-primary" wire:click="saveAsTableView">Save</button>
        </footer>
    </aside>
@endif

@if ($tableViewManagerOpen)
    <div class="tp-table-views-overlay" wire:click="closeTableViewManager"></div>
    <aside class="tp-table-views-slideover" role="dialog" aria-label="View manager">
        <header class="tp-table-views-slideover-header">
            <h2>View manager</h2>
            <button type="button" class="tp-table-views-icon-btn" wire:click="closeTableViewManager" aria-label="Close">
                <x-filament::icon icon="heroicon-o-x-mark" class="tp-table-views-chip-icon" />
            </button>
        </header>
        <div class="tp-table-views-slideover-body">
            @forelse ($manageableViews as $view)
                <div class="tp-table-views-manager-item {{ $editingTableViewId === (int) $view->getKey() ? 'is-active' : '' }}">
                    <button
                        type="button"
                        class="tp-table-views-manager-row"
                        wire:click="editTableView({{ $view->getKey() }})"
                    >
                        <span>{{ $view->name }}</span>
                        <span class="tp-table-views-manager-meta">
                            @if ($view->is_global) Global @elseif ($view->is_public) Public @else Personal @endif
                            @if ($view->is_favorite) · Favorite @endif
                        </span>
                    </button>
                    <button
                        type="button"
                        class="tp-table-views-action is-danger"
                        wire:click="deleteTableView({{ $view->getKey() }})"
                        wire:confirm="Delete this saved view? This cannot be undone."
                    >
                        Remove
                    </button>
                </div>
            @empty
                <p class="tp-table-views-empty">No saved views yet. Use Quick Save to keep the current filters, sort, and columns.</p>
            @endforelse

            @if ($editingTableViewId)
                <div class="tp-table-views-editor">
                    <label class="tp-table-views-field">
                        <span>Name</span>
                        <input type="text" wire:model="tableViewForm.name" maxlength="100" />
                    </label>
                    <label class="tp-table-views-field">
                        <span>Icon</span>
                        <select wire:model="tableViewForm.icon">
                            <option value="">None</option>
                            @foreach ($iconOptions as $icon)
                                <option value="{{ $icon }}">{{ str($icon)->afterLast('-')->replace('-', ' ')->title() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="tp-table-views-field">
                        <span>Color</span>
                        <select wire:model="tableViewForm.color">
                            @foreach ($colorOptions as $color)
                                <option value="{{ $color }}">{{ ucfirst($color) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="tp-table-views-check">
                        <input type="checkbox" wire:model="tableViewForm.is_favorite" />
                        <span>Favorite (show on bar)</span>
                    </label>
                    @if ($canShare)
                        <label class="tp-table-views-check">
                            <input type="checkbox" wire:model="tableViewForm.is_public" />
                            <span>Public (this organization)</span>
                        </label>
                        <label class="tp-table-views-check">
                            <input type="checkbox" wire:model="tableViewForm.is_global" />
                            <span>Global favorite</span>
                        </label>
                    @endif
                    <label class="tp-table-views-check">
                        <input type="checkbox" wire:model="tableViewForm.is_default" />
                        <span>Load by default</span>
                    </label>
                    <div class="tp-table-views-editor-actions">
                        <button type="button" class="tp-table-views-action is-primary" wire:click="updateTableView">Save changes</button>
                        <button
                            type="button"
                            class="tp-table-views-action is-danger"
                            wire:click="deleteTableView({{ $editingTableViewId }})"
                            wire:confirm="Delete this saved view? This cannot be undone."
                        >
                            Delete
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </aside>
@endif
