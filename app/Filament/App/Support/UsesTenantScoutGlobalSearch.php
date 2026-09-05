<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use App\Support\Scout\TenantModelSearch;
use Filament\Actions\Action;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Filament global search backed by tenant Scout indexes with SQL fallback.
 *
 * @template TModel of Model
 */
trait UsesTenantScoutGlobalSearch
{
    protected static bool $isGloballySearchable = true;

    /**
     * @return list<string>
     */
    abstract protected static function tenantScoutSqlColumns(): array;

    public static function getGlobalSearchResults(string $search): Collection
    {
        $query = static::getGlobalSearchEloquentQuery();

        TenantModelSearch::constrain(
            $query,
            static::getModel(),
            $search,
            static::tenantScoutSqlColumns(),
        );

        return $query
            ->limit(static::getGlobalSearchResultsLimit())
            ->get()
            ->map(function (Model $record): ?GlobalSearchResult {
                $url = static::getGlobalSearchResultUrl($record);

                if (blank($url)) {
                    return null;
                }

                return new GlobalSearchResult(
                    title: static::getGlobalSearchResultTitle($record),
                    url: $url,
                    details: static::getGlobalSearchResultDetails($record),
                    actions: array_map(
                        fn (Action $action) => $action->hasRecord() ? $action : $action->record($record),
                        static::getGlobalSearchResultActions($record),
                    ),
                );
            })
            ->filter();
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return static::getRecordTitle($record);
    }

    /**
     * @return Builder<Model>
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }
}
