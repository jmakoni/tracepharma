<?php

namespace App\Actions\Fda;

/**
 * Promote fda_wdd_3pl_staging rows into catalog_sites + catalog_atp_licenses.
 *
 * Retired: FDA registry import owns WDD facilities; handle() is a no-op for callers.
 */
final class PromoteFdaWdd3plToCatalogSites
{
    /**
     * @param  bool  $force  Promote even from a staging table that collapsed against the last import
     * @return array{
     *     processed: int,
     *     sites_matched: int,
     *     sites_created: int,
     *     licenses_upserted: int,
     *     licenses_relocated: int,
     *     licenses_delisted: int,
     *     skipped: int,
     *     expirations_unparsed: int
     * }
     */
    public function handle(bool $dryRun = false, bool $force = false): array
    {
        \Illuminate\Support\Facades\Log::info('PromoteFdaWdd3plToCatalogSites is retired; FDA registry import owns WDD facilities.');

        return [
            'processed' => 0,
            'sites_matched' => 0,
            'sites_created' => 0,
            'licenses_upserted' => 0,
            'licenses_relocated' => 0,
            'licenses_delisted' => 0,
            'skipped' => 0,
            'expirations_unparsed' => 0,
        ];
    }
}
