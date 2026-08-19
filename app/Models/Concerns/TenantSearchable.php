<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Log;
use Laravel\Scout\Searchable;
use Throwable;

/**
 * Tenant-scoped Scout indexing ({@see IndexesTenantSearch}) with conflict resolution
 * against {@see Searchable::shouldBeSearchable()}.
 *
 * Index writes never abort the Eloquent save. Search already falls back to SQL
 * when Meilisearch is down ({@see \App\Support\Scout\TenantModelSearch}).
 */
trait TenantSearchable
{
    use IndexesTenantSearch;
    use Searchable {
        IndexesTenantSearch::searchableAs insteadof Searchable;
        IndexesTenantSearch::shouldBeSearchable insteadof Searchable;
        Searchable::syncMakeSearchable as scoutSyncMakeSearchable;
        Searchable::syncRemoveFromSearch as scoutSyncRemoveFromSearch;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>  $models
     */
    public function syncMakeSearchable($models): void
    {
        try {
            $this->scoutSyncMakeSearchable($models);
        } catch (Throwable $e) {
            $this->logFailedSearchSync('update', $e);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>  $models
     */
    public function syncRemoveFromSearch($models): void
    {
        try {
            $this->scoutSyncRemoveFromSearch($models);
        } catch (Throwable $e) {
            $this->logFailedSearchSync('delete', $e);
        }
    }

    private function logFailedSearchSync(string $operation, Throwable $e): void
    {
        Log::warning('Scout index '.$operation.' skipped; search will use SQL until the index is reachable.', [
            'model' => static::class,
            'index' => $this->searchableAs(),
            'exception' => $e->getMessage(),
        ]);
    }
}
