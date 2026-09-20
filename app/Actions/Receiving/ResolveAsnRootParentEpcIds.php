<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\InboundShipment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve ASN aggregation root parent EPC ids for a document or shipment union.
 *
 * Extracted from OpenReceivingSessionFromDocument so Sync/Attach can resolve roots
 * without a soft DI cycle through app(OpenReceivingSessionFromDocument).
 */
final class ResolveAsnRootParentEpcIds
{
    /**
     * Union of root parents across all shipment member documents in receiving-allowed status.
     *
     * @param  list<string>  $allowedStatuses
     * @return list<int>
     */
    public function resolveUnionRootParentEpcIds(InboundShipment $shipment, array $allowedStatuses): array
    {
        $documents = EpcisDocument::query()
            ->where('inbound_shipment_id', $shipment->getKey())
            ->whereIn('status', $allowedStatuses)
            ->orderBy('id')
            ->get();

        $ids = [];
        foreach ($documents as $document) {
            foreach ($this->resolveRootParentEpcIds($document) as $epcId) {
                $ids[$epcId] = true;
            }
        }

        $rootIds = array_map('intval', array_keys($ids));
        sort($rootIds);

        return $rootIds;
    }

    /**
     * Root parents: aggregation_links established by this document whose parent is not a child
     * of another link on the same document. Prefer SSCC parents when any exist.
     *
     * @return list<int>
     */
    public function resolveRootParentEpcIds(EpcisDocument $document): array
    {
        $linkQuery = AggregationLink::query()
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', $this->documentEventIdsSubquery($document));

        $parentIds = (clone $linkQuery)
            ->distinct()
            ->pluck('parent_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($parentIds === []) {
            return [];
        }

        $childIds = (clone $linkQuery)
            ->whereIn('child_epc_id', $parentIds)
            ->distinct()
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $childIdSet = array_fill_keys($childIds, true);
        $rootIds = array_values(array_filter(
            $parentIds,
            fn (int $id): bool => ! isset($childIdSet[$id]),
        ));

        if ($rootIds === []) {
            return [];
        }

        $ssccRootIds = Epc::query()
            ->whereIn('id', $rootIds)
            ->where('epc_type', 'sscc')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ssccRootIds !== []) {
            return $ssccRootIds;
        }

        sort($rootIds);

        return $rootIds;
    }

    /**
     * @return \Closure(Builder): void
     */
    private function documentEventIdsSubquery(EpcisDocument $document): \Closure
    {
        return function ($query) use ($document): void {
            $query->select('id')
                ->from('epcis_events')
                ->where('document_id', $document->getKey());

            if (
                Schema::hasColumn('epcis_events', 'ingest_generation')
                && Schema::hasColumn('epcis_documents', 'ingest_generation')
                && filled($document->getAttribute('ingest_generation'))
            ) {
                $query->where('ingest_generation', $document->getAttribute('ingest_generation'));
            }
        };
    }
}
