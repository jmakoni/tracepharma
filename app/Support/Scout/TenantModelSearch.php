<?php

namespace App\Support\Scout;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Throwable;

/**
 * Resilient tenant-model search: Scout first, SQL LIKE when the index cannot answer.
 */
final class TenantModelSearch
{
    /**
     * @param  class-string<Model&Searchable>  $modelClass
     * @param  list<string>  $sqlColumns
     */
    public static function constrain(
        Builder $query,
        string $modelClass,
        string $search,
        array $sqlColumns,
    ): void {
        if (blank($search)) {
            return;
        }

        if (self::applyScoutKeys($query, $modelClass, $search)) {
            return;
        }

        self::applySqlLike($query, $search, $sqlColumns);
    }

    /**
     * @param  class-string<Model&Searchable>  $modelClass
     */
    public static function applyScoutKeys(Builder $query, string $modelClass, string $search): bool
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return false;
        }

        try {
            $builder = $modelClass::search($search);

            if (function_exists('tenancy') && tenancy()->initialized) {
                $builder->where('tenant_id', (string) tenant('id'));
            }

            $keys = $builder->keys();

            if ($keys->isEmpty()) {
                return false;
            }

            $query->whereKey($keys);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $columns
     */
    public static function applySqlLike(Builder $query, string $search, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $term = '%'.$search.'%';

        $query->where(function (Builder $inner) use ($columns, $term): void {
            $isFirst = true;

            foreach ($columns as $column) {
                $method = $isFirst ? 'where' : 'orWhere';
                $inner->{$method}($column, 'like', $term);
                $isFirst = false;
            }
        });
    }
}
