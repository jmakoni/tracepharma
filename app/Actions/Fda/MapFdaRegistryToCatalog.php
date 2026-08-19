<?php

namespace App\Actions\Fda;

final class MapFdaRegistryToCatalog
{
    /**
     * @param  list<int>|null  $organizationIds
     * @return array{
     *     partners_linked: int,
     *     partners_created: int,
     *     sites_linked: int,
     *     sites_created: int,
     *     licenses_upserted: int,
     *     products_linked: int,
     *     import_run_id: int
     * }
     */
    public function handle(?array $organizationIds = null): array
    {
        \Illuminate\Support\Facades\Log::info('MapFdaRegistryToCatalog is retired; fda_* is the source of truth.');

        return [
            'partners_linked' => 0,
            'partners_created' => 0,
            'sites_linked' => 0,
            'sites_created' => 0,
            'licenses_upserted' => 0,
            'products_linked' => 0,
            'import_run_id' => 0,
        ];
    }
}
