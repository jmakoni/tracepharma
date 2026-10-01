<?php

namespace Tracepharma\FilamentTableViews\Concerns;

use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tracepharma\FilamentTableViews\FilamentTableViewsPlugin;
use Tracepharma\FilamentTableViews\Models\TableView;
use Tracepharma\FilamentTableViews\Support\PresetView;
use Tracepharma\FilamentTableViews\Support\TableViewState;

trait HasTableViews
{
    public ?string $activeTableView = null;

    public bool $tableViewDidApplyInitialState = false;

    public bool $tableViewManagerOpen = false;

    public bool $tableViewSaveAsOpen = false;

    /**
     * @var array{
     *     name: string,
     *     icon: ?string,
     *     color: string,
     *     is_favorite: bool,
     *     is_public: bool,
     *     is_global: bool,
     *     is_default: bool
     * }
     */
    public array $tableViewForm = [
        'name' => '',
        'icon' => '',
        'color' => 'primary',
        'is_favorite' => true,
        'is_public' => false,
        'is_global' => false,
        'is_default' => false,
    ];

    public ?int $editingTableViewId = null;

    /**
     * @return array<string, PresetView>
     */
    public function getPresetViews(): array
    {
        return [];
    }

    public function getTableViewsTableKey(): string
    {
        return static::class;
    }

    public function canShareTableViews(): bool
    {
        $user = auth()->user();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        $permission = FilamentTableViewsPlugin::tryGet()?->getSharePermission() ?? 'users.manage';

        return $user->can($permission);
    }

    public function renderingHasTableViews(): void
    {
        if ($this->tableViewDidApplyInitialState) {
            return;
        }

        $this->tableViewDidApplyInitialState = true;

        if ($this->activeTableView === null) {
            $this->activeTableView = $this->resolveInitialTableViewKey();
        }

        if ($this->hasExplicitTableUrlState()) {
            return;
        }

        $this->applyActiveTableViewState();
    }

    /**
     * @return Builder<Model>|Relation|null
     */
    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();

        if (! $query instanceof Builder) {
            return $query;
        }

        return $this->applyActiveTableViewQuery($query);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        $query = parent::modifyQueryWithActiveTab($query, $isResolvingRecord);

        return $this->applyActiveTableViewQuery($query);
    }

    public function getTabsContentComponent(): Component
    {
        $bar = Html::make(fn (): string => $this->renderTableViewsBar()->render());

        if ($this->getTabs() === []) {
            return $bar;
        }

        return Group::make([
            $bar,
            parent::getTabsContentComponent(),
        ]);
    }

    public function applyTableView(string $key): void
    {
        if (! $this->tableViewKeyIsValid($key)) {
            return;
        }

        $this->activeTableView = $key;
        session([$this->tableViewsSessionKey() => $key]);
        $this->applyActiveTableViewState();

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function quickSaveTableView(): void
    {
        $saved = $this->activeSavedTableView();

        if ($saved !== null && $this->userOwnsTableView($saved)) {
            $saved->state = TableViewState::snapshot($this);
            $saved->save();

            Notification::make()
                ->title('View updated')
                ->body($saved->name)
                ->success()
                ->send();

            return;
        }

        $this->tableViewForm = [
            'name' => '',
            'icon' => '',
            'color' => 'primary',
            'is_favorite' => true,
            'is_public' => false,
            'is_global' => false,
            'is_default' => false,
        ];
        $this->tableViewSaveAsOpen = true;
    }

    public function saveAsTableView(): void
    {
        if (! $this->tableViewsStorageReady()) {
            Notification::make()
                ->title('Saved views unavailable')
                ->body('Run tenant migrations to create the table_views table.')
                ->danger()
                ->send();

            return;
        }

        $name = trim((string) ($this->tableViewForm['name'] ?? ''));

        if ($name === '') {
            Notification::make()
                ->title('Name required')
                ->body('Enter a name for this view.')
                ->warning()
                ->send();

            return;
        }

        $isGlobal = (bool) ($this->tableViewForm['is_global'] ?? false);
        $isPublic = (bool) ($this->tableViewForm['is_public'] ?? false);

        if (($isGlobal || $isPublic) && ! $this->canShareTableViews()) {
            Notification::make()
                ->title('Not authorized')
                ->body('Sharing a view as public or global requires user management permission.')
                ->danger()
                ->send();

            return;
        }

        $user = auth()->user();
        if (! $user instanceof Authenticatable) {
            return;
        }

        $view = new TableView([
            'user_id' => $isGlobal ? null : $user->getKey(),
            'table_key' => $this->getTableViewsTableKey(),
            'name' => $name,
            'icon' => filled($this->tableViewForm['icon'] ?? null) ? $this->tableViewForm['icon'] : null,
            'color' => $this->tableViewForm['color'] ?: 'primary',
            'is_favorite' => (bool) ($this->tableViewForm['is_favorite'] ?? true),
            'is_public' => $isGlobal ? false : $isPublic,
            'is_global' => $isGlobal,
            'is_default' => (bool) ($this->tableViewForm['is_default'] ?? false),
            'state' => TableViewState::snapshot($this),
        ]);
        $view->clearConflictingDefaults();
        $view->save();

        $this->tableViewSaveAsOpen = false;
        $this->applyTableView($this->savedTableViewKey($view));

        Notification::make()
            ->title('View saved')
            ->body($view->name)
            ->success()
            ->send();
    }

    public function updateTableView(): void
    {
        $view = $this->findManageableTableView($this->editingTableViewId);

        if ($view === null) {
            return;
        }

        $name = trim((string) ($this->tableViewForm['name'] ?? ''));
        if ($name === '') {
            Notification::make()
                ->title('Name required')
                ->warning()
                ->send();

            return;
        }

        $isGlobal = (bool) ($this->tableViewForm['is_global'] ?? false);
        $isPublic = (bool) ($this->tableViewForm['is_public'] ?? false);

        if (($isGlobal || $isPublic || $view->is_global || $view->is_public) && ! $this->userOwnsTableView($view) && ! $this->canShareTableViews()) {
            Notification::make()->title('Not authorized')->danger()->send();

            return;
        }

        if (($isGlobal || $isPublic) && ! $this->canShareTableViews()) {
            Notification::make()
                ->title('Not authorized')
                ->body('Sharing a view as public or global requires user management permission.')
                ->danger()
                ->send();

            return;
        }

        $user = auth()->user();
        $view->fill([
            'name' => $name,
            'icon' => filled($this->tableViewForm['icon'] ?? null) ? $this->tableViewForm['icon'] : null,
            'color' => $this->tableViewForm['color'] ?: 'primary',
            'is_favorite' => (bool) ($this->tableViewForm['is_favorite'] ?? false),
            'is_public' => $isGlobal ? false : $isPublic,
            'is_global' => $isGlobal,
            'is_default' => (bool) ($this->tableViewForm['is_default'] ?? false),
            'user_id' => $isGlobal ? null : ($view->user_id ?? ($user instanceof Authenticatable ? $user->getKey() : null)),
        ]);
        $view->clearConflictingDefaults();
        $view->save();

        Notification::make()
            ->title('View updated')
            ->body($view->name)
            ->success()
            ->send();
    }

    public function deleteTableView(int $id): void
    {
        $view = $this->findManageableTableView($id);

        if ($view === null) {
            return;
        }

        $key = $this->savedTableViewKey($view);
        $view->delete();

        if ($this->activeTableView === $key) {
            $this->applyTableView($this->resolveInitialTableViewKey() ?? '');
        }

        if ($this->editingTableViewId === $id) {
            $this->editingTableViewId = null;
        }

        Notification::make()
            ->title('View deleted')
            ->success()
            ->send();
    }

    public function toggleTableViewFavorite(int $id): void
    {
        $view = $this->findManageableTableView($id);

        if ($view === null) {
            return;
        }

        $view->is_favorite = ! $view->is_favorite;
        $view->save();
    }

    public function editTableView(int $id): void
    {
        $view = $this->findManageableTableView($id);

        if ($view === null) {
            return;
        }

        $this->editingTableViewId = $id;
        $this->tableViewForm = [
            'name' => $view->name,
            'icon' => $view->icon ?? '',
            'color' => $view->color ?: 'primary',
            'is_favorite' => $view->is_favorite,
            'is_public' => $view->is_public,
            'is_global' => $view->is_global,
            'is_default' => $view->is_default,
        ];
    }

    public function openTableViewManager(): void
    {
        $this->tableViewManagerOpen = true;
        $this->editingTableViewId = null;
        $this->tableViewForm = [
            'name' => '',
            'icon' => '',
            'color' => 'primary',
            'is_favorite' => true,
            'is_public' => false,
            'is_global' => false,
            'is_default' => false,
        ];
    }

    public function closeTableViewManager(): void
    {
        $this->tableViewManagerOpen = false;
        $this->editingTableViewId = null;
    }

    public function closeTableViewSaveAs(): void
    {
        $this->tableViewSaveAsOpen = false;
    }

    public function renderTableViewsBar(): View
    {
        return view('filament-table-views::components.favorites-bar', [
            'items' => $this->getTableViewBarItems(),
            'canShare' => $this->canShareTableViews(),
            'activeKey' => $this->activeTableView,
            'canQuickUpdate' => $this->activeSavedTableView() !== null
                && $this->userOwnsTableView($this->activeSavedTableView()),
            'tableViewSaveAsOpen' => $this->tableViewSaveAsOpen,
            'tableViewManagerOpen' => $this->tableViewManagerOpen,
            'tableViewForm' => $this->tableViewForm,
            'editingTableViewId' => $this->editingTableViewId,
            'manageableViews' => $this->getManageableTableViews(),
            'iconOptions' => $this->getTableViewIconOptions(),
            'colorOptions' => $this->getTableViewColorOptions(),
        ]);
    }

    /**
     * @return list<array{key: string, label: string, icon: ?string, color: ?string, active: bool, is_preset: bool}>
     */
    public function getTableViewBarItems(): array
    {
        $items = [];

        foreach ($this->getPresetViews() as $key => $preset) {
            if (! $preset instanceof PresetView || ! $preset->isFavorite()) {
                continue;
            }

            $viewKey = $this->presetTableViewKey((string) $key);
            $items[] = [
                'key' => $viewKey,
                'label' => $preset->getName(),
                'icon' => $preset->getIcon(),
                'color' => $preset->getColor(),
                'active' => $this->activeTableView === $viewKey,
                'is_preset' => true,
            ];
        }

        $user = auth()->user();
        if (! $user instanceof Authenticatable) {
            return $items;
        }

        $favorites = $this->tableViewsStorageReady()
            ? TableView::query()
                ->forTable($this->getTableViewsTableKey())
                ->visibleTo($user)
                ->favorites()
                ->orderBy('name')
                ->get()
            : collect();

        foreach ($favorites as $view) {
            $viewKey = $this->savedTableViewKey($view);
            $items[] = [
                'key' => $viewKey,
                'label' => $view->name,
                'icon' => $view->icon,
                'color' => $view->color,
                'active' => $this->activeTableView === $viewKey,
                'is_preset' => false,
            ];
        }

        return $items;
    }

    /**
     * @return list<TableView>
     */
    public function getManageableTableViews(): array
    {
        $user = auth()->user();
        if (! $user instanceof Authenticatable || ! $this->tableViewsStorageReady()) {
            return [];
        }

        return TableView::query()
            ->forTable($this->getTableViewsTableKey())
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->getKey());

                if ($this->canShareTableViews()) {
                    $query->orWhere('is_public', true)
                        ->orWhere('is_global', true);
                }
            })
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function getTableViewIconOptions(): array
    {
        return [
            'heroicon-o-funnel',
            'heroicon-o-star',
            'heroicon-o-inbox',
            'heroicon-o-exclamation-triangle',
            'heroicon-o-check-circle',
            'heroicon-o-clock',
            'heroicon-o-user',
            'heroicon-o-globe-alt',
            'heroicon-o-document-text',
        ];
    }

    /**
     * @return list<string>
     */
    public function getTableViewColorOptions(): array
    {
        return ['primary', 'success', 'warning', 'danger', 'info', 'gray'];
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyActiveTableViewQuery(Builder $query): Builder
    {
        $preset = $this->activePresetView();

        if ($preset?->getQuery() === null) {
            return $query;
        }

        return ($preset->getQuery())($query);
    }

    protected function applyActiveTableViewState(): void
    {
        $preset = $this->activePresetView();
        if ($preset !== null) {
            $state = $preset->defaultState();
            if (($state['filters'] ?? []) === []
                && ($state['search'] ?? '') === ''
                && ($state['sort'] ?? null) === null
                && ($state['grouping'] ?? null) === null
                && ($state['columns'] ?? []) === []) {
                return;
            }

            TableViewState::apply($this, $state);

            return;
        }

        $saved = $this->activeSavedTableView();
        if ($saved !== null) {
            TableViewState::apply($this, $saved->state ?? []);
        }
    }

    protected function resolveInitialTableViewKey(): ?string
    {
        $sessionKey = session($this->tableViewsSessionKey());
        if (is_string($sessionKey) && $this->tableViewKeyIsValid($sessionKey)) {
            return $sessionKey;
        }

        $user = auth()->user();
        if ($user instanceof Authenticatable && $this->tableViewsStorageReady()) {
            $personalDefault = TableView::query()
                ->forTable($this->getTableViewsTableKey())
                ->where('user_id', $user->getKey())
                ->where('is_default', true)
                ->where('is_global', false)
                ->first();

            if ($personalDefault !== null) {
                return $this->savedTableViewKey($personalDefault);
            }

            $globalDefault = TableView::query()
                ->forTable($this->getTableViewsTableKey())
                ->where('is_global', true)
                ->where('is_default', true)
                ->first();

            if ($globalDefault !== null) {
                return $this->savedTableViewKey($globalDefault);
            }
        }

        foreach ($this->getPresetViews() as $key => $preset) {
            if ($preset instanceof PresetView && $preset->isDefault()) {
                return $this->presetTableViewKey((string) $key);
            }
        }

        return null;
    }

    protected function tableViewKeyIsValid(string $key): bool
    {
        if (str_starts_with($key, 'preset:')) {
            $presetKey = substr($key, 7);

            return array_key_exists($presetKey, $this->getPresetViews());
        }

        if (str_starts_with($key, 'saved:')) {
            return $this->findVisibleTableView((int) substr($key, 6)) !== null;
        }

        return false;
    }

    protected function presetTableViewKey(string $key): string
    {
        return 'preset:'.$key;
    }

    protected function savedTableViewKey(TableView $view): string
    {
        return 'saved:'.$view->getKey();
    }

    protected function activePresetView(): ?PresetView
    {
        if (! is_string($this->activeTableView) || ! str_starts_with($this->activeTableView, 'preset:')) {
            return null;
        }

        $preset = $this->getPresetViews()[substr($this->activeTableView, 7)] ?? null;

        return $preset instanceof PresetView ? $preset : null;
    }

    protected function activeSavedTableView(): ?TableView
    {
        if (! is_string($this->activeTableView) || ! str_starts_with($this->activeTableView, 'saved:')) {
            return null;
        }

        return $this->findVisibleTableView((int) substr($this->activeTableView, 6));
    }

    protected function findVisibleTableView(int $id): ?TableView
    {
        $user = auth()->user();
        if (! $user instanceof Authenticatable || $id < 1 || ! $this->tableViewsStorageReady()) {
            return null;
        }

        return TableView::query()
            ->forTable($this->getTableViewsTableKey())
            ->visibleTo($user)
            ->whereKey($id)
            ->first();
    }

    protected function findManageableTableView(?int $id): ?TableView
    {
        if ($id === null || $id < 1 || ! $this->tableViewsStorageReady()) {
            return null;
        }

        $user = auth()->user();
        if (! $user instanceof Authenticatable) {
            return null;
        }

        $view = TableView::query()
            ->forTable($this->getTableViewsTableKey())
            ->whereKey($id)
            ->first();

        if ($view === null) {
            return null;
        }

        if ($this->userOwnsTableView($view)) {
            return $view;
        }

        if ($this->canShareTableViews() && ($view->is_public || $view->is_global)) {
            return $view;
        }

        return null;
    }

    protected function userOwnsTableView(TableView $view): bool
    {
        $user = auth()->user();

        return $user instanceof Authenticatable
            && $view->user_id !== null
            && (int) $view->user_id === (int) $user->getKey();
    }

    protected function tableViewsSessionKey(): string
    {
        return 'table-views.'.$this->getTableViewsTableKey();
    }

    protected function tableViewsStorageReady(): bool
    {
        return TableView::tableExists();
    }

    protected function hasExplicitTableUrlState(): bool
    {
        return request()->has('filters')
            || request()->has('search')
            || request()->has('sort')
            || request()->has('grouping');
    }
}
