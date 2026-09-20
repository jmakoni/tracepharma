<?php

namespace App\Support\Shipping;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Gs1\Sgln;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\Gs1DualDisplay;

/**
 * Classify pasted scans against site on-hand custody for the Investigate tab.
 */
final class OnHandInvestigate
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ShippableEpcsAtSite $shippable,
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
    ) {}

    /**
     * @return list<array{
     *     scan: string,
     *     status: 'on_hand'|'other_site'|'quarantined'|'not_found'|'unknown',
     *     identifier: string,
     *     site_label: ?string,
     *     asset_url: ?string,
     *     epc_id: ?int
     * }>
     */
    public function classify(int $siteId, string $paste): array
    {
        $lines = preg_split('/\R+/', $paste) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $scan = trim($line);
            if ($scan === '') {
                continue;
            }

            $resolved = $this->resolveEpcFromScan->handle($scan);
            $epc = $resolved['epc'] ?? null;

            if (! $epc instanceof Epc) {
                $out[] = [
                    'scan' => $scan,
                    'status' => 'not_found',
                    'identifier' => $scan,
                    'site_label' => null,
                    'asset_url' => AssetTrackingUrl::url($scan),
                    'epc_id' => null,
                ];

                continue;
            }

            $identifier = Gs1DualDisplay::forEpc($epc)['primary'];
            $epcId = (int) $epc->getKey();
            $quarantined = QuarantineHold::query()->open()->where('epc_id', $epcId)->exists();

            if ($this->shippable->isOnHandAtSite($siteId, $epcId)) {
                $out[] = [
                    'scan' => $scan,
                    'status' => $quarantined ? 'quarantined' : 'on_hand',
                    'identifier' => $identifier,
                    'site_label' => null,
                    'asset_url' => AssetTrackingUrl::forEpc($epc),
                    'epc_id' => $epcId,
                ];

                continue;
            }

            $gln = $this->lastKnownGln->forEpc($epcId);
            $otherSite = $gln !== null
                ? Site::query()->where('gln', Sgln::normalizeGln($gln) ?? $gln)->value('name')
                : null;

            $out[] = [
                'scan' => $scan,
                'status' => $otherSite !== null ? 'other_site' : ($quarantined ? 'quarantined' : 'unknown'),
                'identifier' => $identifier,
                'site_label' => $otherSite !== null ? (string) $otherSite : null,
                'asset_url' => AssetTrackingUrl::forEpc($epc),
                'epc_id' => $epcId,
            ];
        }

        return $out;
    }
}
