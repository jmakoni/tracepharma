<?php

namespace App\Support\Receiving;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringSession;
use Illuminate\Support\Facades\Schema;

/**
 * PDG-safe child inference: only children established by inbound or transfer-ship
 * AggregationEvents — never bare open warehouse aggregation links.
 */
final class ResolveInboundAggregationChildEpcs
{
    private static ?bool $hasDocumentShipmentColumn = null;

    private static ?bool $hasSessionShipmentColumn = null;

    /**
     * @return list<int>
     */
    public function childEpcIdsForParent(
        ReceivingSession $session,
        Epc $parentEpc,
        ?int $preferredDocumentId = null,
    ): array {
        $documentIds = $this->aggregationDocumentIdsForSession($session, $preferredDocumentId);

        if ($documentIds === []) {
            return [];
        }

        $query = AggregationLink::query()
            ->where('parent_epc_id', $parentEpc->getKey())
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', function ($sub) use ($documentIds): void {
                $sub->select('id')
                    ->from('epcis_events')
                    ->whereIn('document_id', $documentIds);
            });

        if (
            $preferredDocumentId !== null
            && Schema::hasColumn('epcis_events', 'ingest_generation')
            && Schema::hasColumn('epcis_documents', 'ingest_generation')
        ) {
            $document = EpcisDocument::query()->find($preferredDocumentId);
            if (
                $document !== null
                && filled($document->getAttribute('ingest_generation'))
            ) {
                $generation = $document->getAttribute('ingest_generation');
                $query->whereIn('established_by_event_id', function ($sub) use ($preferredDocumentId, $generation): void {
                    $sub->select('id')
                        ->from('epcis_events')
                        ->where('document_id', $preferredDocumentId)
                        ->where('ingest_generation', $generation);
                });
            }
        }

        return $query
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function parentEpcIdsForChild(
        ReceivingSession $session,
        Epc $childEpc,
        ?int $preferredDocumentId = null,
    ): array {
        $documentIds = $this->aggregationDocumentIdsForSession($session, $preferredDocumentId);

        if ($documentIds === []) {
            return [];
        }

        return AggregationLink::query()
            ->where('child_epc_id', $childEpc->getKey())
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', function ($sub) use ($documentIds): void {
                $sub->select('id')
                    ->from('epcis_events')
                    ->whereIn('document_id', $documentIds);
            })
            ->pluck('parent_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function aggregationDocumentIdsForSession(
        ReceivingSession $session,
        ?int $preferredDocumentId = null,
    ): array {
        if ($preferredDocumentId !== null) {
            return [$preferredDocumentId];
        }

        if ($session->isTransferReceive() && $session->transferring_session_id !== null) {
            $transfer = TransferringSession::query()->find($session->transferring_session_id);
            $transferDocId = $transfer?->transfer_epcis_document_id;

            return $transferDocId !== null ? [(int) $transferDocId] : [];
        }

        if (
            $this->hasSessionShipmentColumn()
            && $session->inbound_shipment_id !== null
            && $this->hasDocumentShipmentColumn()
        ) {
            $ids = EpcisDocument::query()
                ->where('inbound_shipment_id', $session->inbound_shipment_id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($ids !== []) {
                return $ids;
            }
        }

        if ($session->epcis_document_id !== null) {
            return [(int) $session->epcis_document_id];
        }

        if ($session->matched_epcis_document_id !== null) {
            return [(int) $session->matched_epcis_document_id];
        }

        return [];
    }

    private function hasSessionShipmentColumn(): bool
    {
        return self::$hasSessionShipmentColumn ??= Schema::hasColumn('receiving_sessions', 'inbound_shipment_id');
    }

    private function hasDocumentShipmentColumn(): bool
    {
        return self::$hasDocumentShipmentColumn ??= Schema::hasColumn('epcis_documents', 'inbound_shipment_id');
    }
}
