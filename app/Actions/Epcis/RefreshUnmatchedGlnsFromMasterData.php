<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisUnmatchedGln;
use Illuminate\Support\Facades\Schema;

/**
 * Drop unmatched-GLN rows that now resolve in tenant master data.
 * Does not rewrite events, sessions, or aggregation links.
 */
final class RefreshUnmatchedGlnsFromMasterData
{
    public function __construct(
        private readonly ResolveGlnToMasterData $resolveGln,
    ) {}

    public function handle(EpcisDocument $document): int
    {
        if (! Schema::hasTable('epcis_unmatched_glns')) {
            return 0;
        }

        $rows = EpcisUnmatchedGln::query()
            ->where('document_id', $document->getKey())
            ->get();

        $removed = 0;

        foreach ($rows->groupBy('gln') as $gln => $group) {
            $resolved = $this->resolveGln->handle((string) $gln);
            if (! $this->isKnownParty($resolved)) {
                continue;
            }

            $removed += EpcisUnmatchedGln::query()
                ->where('document_id', $document->getKey())
                ->where('gln', (string) $gln)
                ->delete();
        }

        return $removed;
    }

    /**
     * @param  array{trading_partner_id: ?int, site_id: ?int, location_device_id: ?int, read_point_id: ?int}  $resolved
     */
    private function isKnownParty(array $resolved): bool
    {
        return $resolved['trading_partner_id'] !== null
            || $resolved['site_id'] !== null
            || $resolved['location_device_id'] !== null
            || $resolved['read_point_id'] !== null;
    }
}
