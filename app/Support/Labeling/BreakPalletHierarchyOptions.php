<?php

namespace App\Support\Labeling;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;

/**
 * Lean hierarchy options for Break pallet & reship Filament forms.
 *
 * Only pallets and children the break gate will accept are offered: quarantined units
 * need the hold resolved first, and out-of-custody ones belong to a partner's hierarchy
 * that merely appears in our event store. Offering either would stage a selection that
 * BreakPalletAndReship rejects at confirm time.
 */
final class BreakPalletHierarchyOptions
{
    /**
     * Open SSCC parents established by this document's aggregation projection.
     *
     * @return array<string, string> epc_uri => label
     */
    public static function parentSsccOptions(int $documentId): array
    {
        $document = EpcisDocument::query()->find($documentId);

        if ($document === null) {
            return [];
        }

        return self::parentSsccOptionsForDocument($document);
    }

    /**
     * @return array<string, string> epc_uri => label
     */
    public static function parentSsccOptionsForDocument(EpcisDocument $document): array
    {
        $parentIds = AggregationLink::query()
            ->open()
            ->forDocumentProjection($document)
            ->distinct()
            ->pluck('parent_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $parentIds = self::operableEpcIds($parentIds);

        if ($parentIds === []) {
            return [];
        }

        $counts = AggregationLink::query()
            ->open()
            ->forDocumentProjection($document)
            ->whereIn('parent_epc_id', $parentIds)
            ->selectRaw('parent_epc_id, COUNT(*) as child_count')
            ->groupBy('parent_epc_id')
            ->pluck('child_count', 'parent_epc_id');

        return Epc::query()
            ->whereIn('id', $parentIds)
            ->where('epc_type', 'sscc')
            ->orderBy('id')
            ->get(['id', 'epc_uri', 'sscc18'])
            ->filter(fn (Epc $epc): bool => (int) ($counts[$epc->getKey()] ?? 0) > 0)
            ->mapWithKeys(function (Epc $epc) use ($counts): array {
                $uri = (string) $epc->epc_uri;
                $display = filled($epc->sscc18) ? (string) $epc->sscc18 : $uri;
                $childCount = (int) ($counts[$epc->getKey()] ?? 0);

                return [$uri => $display.' ('.$childCount.' children)'];
            })
            ->all();
    }

    /**
     * @return array<string, string> epc_uri => label
     */
    public static function childEpcOptions(int $documentId, string $parentUrn): array
    {
        $document = EpcisDocument::query()->find($documentId);
        $parentUrn = trim($parentUrn);

        if ($document === null || $parentUrn === '') {
            return [];
        }

        $parent = Epc::query()->where('epc_uri', $parentUrn)->first();

        if ($parent === null) {
            return [];
        }

        $childIds = AggregationLink::query()
            ->open()
            ->forDocumentProjection($document)
            ->where('parent_epc_id', $parent->getKey())
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $childIds = self::operableEpcIds($childIds);

        if ($childIds === []) {
            return [];
        }

        return Epc::query()
            ->whereIn('id', $childIds)
            ->orderBy('id')
            ->get(['id', 'epc_uri', 'epc_type', 'sscc18', 'gtin14', 'serial_number', 'packaging_level'])
            ->mapWithKeys(function (Epc $epc): array {
                $uri = (string) $epc->epc_uri;
                $bits = array_filter([
                    $epc->epc_type,
                    $epc->packaging_level,
                    $epc->sscc18 ?: $epc->gtin14,
                    $epc->serial_number,
                ]);

                return [$uri => implode(' · ', $bits !== [] ? $bits : [$uri])];
            })
            ->all();
    }

    /**
     * @param  list<int>  $epcIds
     * @return list<int>
     */
    private static function operableEpcIds(array $epcIds): array
    {
        if ($epcIds === []) {
            return [];
        }

        $heldEpcIds = app(ReceivingGate::class)->epcIdsBlockedByOpenHold($epcIds);

        return app(EpcCustodyGate::class)->epcIdsInCustody(
            array_values(array_diff($epcIds, $heldEpcIds)),
        );
    }
}
