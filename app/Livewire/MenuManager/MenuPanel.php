<?php

namespace App\Livewire\MenuManager;

use App\Actions\Menus\SyncAppMenuLinks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use NoteBrainsLab\FilamentMenuManager\Livewire\MenuPanel as BaseMenuPanel;

/**
 * Raises the model-source list limit so the full App page/resource catalog is visible.
 */
class MenuPanel extends BaseMenuPanel
{
    public function mount(): void
    {
        app(SyncAppMenuLinks::class)->handle();

        parent::mount();
    }

    public function getModelRecords(string $modelClass): Collection
    {
        if (! class_exists($modelClass)) {
            return collect();
        }

        $query = $modelClass::query();

        if ($this->modelSearch) {
            $table = (new $modelClass)->getTable();
            $columns = Schema::getColumnListing($table);
            $search = $this->modelSearch;

            $query->where(function ($q) use ($columns, $search): void {
                foreach (['name', 'title', 'label', 'navigation_group', 'key'] as $col) {
                    if (in_array($col, $columns, true)) {
                        $q->orWhere($col, 'like', "%{$search}%");
                    }
                }
            });
        }

        if (Schema::hasColumn((new $modelClass)->getTable(), 'sort')) {
            $query->orderBy('sort');
        }

        if (Schema::hasColumn((new $modelClass)->getTable(), 'enabled')) {
            $query->where('enabled', true);
        }

        return $query->limit(200)->get();
    }
}
