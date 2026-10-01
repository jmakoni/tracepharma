<?php

namespace Tracepharma\FilamentTableViews\Support;

use Throwable;

class TableViewState
{
    /**
     * @return array{filters: ?array, search: string, sort: ?string, grouping: ?string, columns: array}
     */
    public static function snapshot(object $livewire): array
    {
        return [
            'filters' => property_exists($livewire, 'tableFilters') ? $livewire->tableFilters : null,
            'search' => property_exists($livewire, 'tableSearch') ? (string) $livewire->tableSearch : '',
            'sort' => property_exists($livewire, 'tableSort') ? $livewire->tableSort : null,
            'grouping' => property_exists($livewire, 'tableGrouping') ? $livewire->tableGrouping : null,
            'columns' => property_exists($livewire, 'tableColumns') && is_array($livewire->tableColumns)
                ? $livewire->tableColumns
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function apply(object $livewire, array $state): void
    {
        if (property_exists($livewire, 'tableFilters')) {
            $filters = $state['filters'] ?? [];
            $livewire->tableFilters = is_array($filters) ? $filters : [];
        }

        if (property_exists($livewire, 'tableSearch')) {
            $livewire->tableSearch = (string) ($state['search'] ?? '');
        }

        if (property_exists($livewire, 'tableSort')) {
            $livewire->tableSort = $state['sort'] ?? null;
        }

        if (property_exists($livewire, 'tableGrouping')) {
            $livewire->tableGrouping = $state['grouping'] ?? null;
        }

        $columns = $state['columns'] ?? [];
        if (is_array($columns) && $columns !== [] && method_exists($livewire, 'applyTableColumnManager')) {
            $livewire->applyTableColumnManager($columns);
        }

        if (method_exists($livewire, 'getTableFiltersForm')) {
            try {
                $livewire->getTableFiltersForm()->fill($livewire->tableFilters ?? []);
            } catch (Throwable) {
                // Form may not be cached yet; Livewire properties still drive the query.
            }
        }
    }
}
