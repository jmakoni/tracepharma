<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;

/**
 * GS1 US DSCSA: one commissioning ObjectEvent is one lot + one expiry.
 * Same GTIN-14 (packaging indicator included) + lot + expiry share one event.
 * Each SSCC stays its own event.
 */
final class GroupCommissioningEpcUris
{
    /**
     * @param  list<string>  $epcUris
     * @return list<list<string>>
     */
    public static function group(array $epcUris): array
    {
        $uris = [];
        foreach ($epcUris as $epcUri) {
            $uri = trim((string) $epcUri);
            if ($uri !== '') {
                $uris[] = $uri;
            }
        }

        if ($uris === []) {
            return [];
        }

        $byUri = collect();
        if (tenancy()->initialized) {
            $byUri = Epc::query()
                ->with('ilmd')
                ->whereIn('epc_uri', $uris)
                ->get()
                ->keyBy('epc_uri');
        }

        $groups = [];
        $order = [];

        foreach ($uris as $uri) {
            $epc = $byUri->get($uri);
            if (! $epc instanceof Epc) {
                $epc = Epc::fromUri($uri);
            }

            $key = self::groupKey($uri, $epc);
            if (! isset($groups[$key])) {
                $groups[$key] = [];
                $order[] = $key;
            }

            $groups[$key][] = $uri;
        }

        return array_map(
            static fn (string $key): array => $groups[$key],
            $order,
        );
    }

    private static function groupKey(string $uri, Epc $epc): string
    {
        $type = strtolower(trim((string) ($epc->epc_type ?? '')));

        if ($type === 'sscc' || str_starts_with(strtolower($uri), 'urn:epc:id:sscc:')) {
            return 'sscc|'.$uri;
        }

        $gtin14 = trim((string) ($epc->gtin14 ?? ''));
        $lot = '';
        $expiry = '';

        $ilmd = $epc->relationLoaded('ilmd') ? $epc->ilmd : null;
        if ($ilmd instanceof EpcIlmd) {
            $lot = trim((string) ($ilmd->lot_number ?? ''));
            $expiry = $ilmd->expiry_date?->format('Y-m-d') ?? '';
        }

        return 'sgtin|'.$gtin14.'|'.$lot.'|'.$expiry;
    }
}
