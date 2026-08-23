<?php

namespace App\Support\Receiving;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Services\Receiving\ReceivingGate;
use App\Support\Epcis\LastGoodIngestProjection;
use Illuminate\Support\Facades\DB;

/**
 * Resolve a dock barcode into EPC + UI chips (TI, quarantine, ASN match, transfer match).
 *
 * Does not invent EPCs — barcode must already exist in the tenant repository.
 */
final class ResolveReceiveScanContext
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ReceivingGate $receivingGate,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     epc: ?Epc,
     *     identity: array<string, mixed>,
     *     ilmd_soft_mismatch: ?array<string, mixed>,
     *     quarantined: bool,
     *     quarantine_hold: ?QuarantineHold,
     *     has_ti: bool,
     *     matched_inbound_document_id: ?int,
     *     matched_inbound_document: ?EpcisDocument,
     *     in_transit_transferring_session_id: ?int,
     *     in_transit_transferring_session: ?TransferringSession,
     *     current_session_id: ?int,
     * }
     */
    public function handle(string $barcode, ?ReceivingSession $currentSession = null): array
    {
        $resolved = $this->resolveEpcFromScan->handle($barcode);
        $epc = $resolved['epc'];

        if ($epc === null) {
            return [
                'ok' => false,
                'message' => 'Barcode not recognized. Serial must already exist from prior EPCIS or commissioning.',
                'epc' => null,
                'identity' => $resolved['identity'],
                'ilmd_soft_mismatch' => $resolved['ilmd_soft_mismatch'],
                'quarantined' => false,
                'quarantine_hold' => null,
                'has_ti' => false,
                'matched_inbound_document_id' => null,
                'matched_inbound_document' => null,
                'in_transit_transferring_session_id' => null,
                'in_transit_transferring_session' => null,
                'current_session_id' => $currentSession?->getKey(),
            ];
        }

        $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
        $hasTi = $this->hasTransactionInformation($epc);
        $inbound = $this->findUnmatchedInboundDocument($epc);
        $transfer = $this->findInTransitTransfer($epc);

        return [
            'ok' => true,
            'message' => $hold !== null
                ? 'Under quarantine. Clear or release before receiving.'
                : ($hasTi ? 'TI present.' : 'TI missing — no shipping/commissioning event on file.'),
            'epc' => $epc,
            'identity' => $resolved['identity'],
            'ilmd_soft_mismatch' => $resolved['ilmd_soft_mismatch'],
            'quarantined' => $hold !== null,
            'quarantine_hold' => $hold,
            'has_ti' => $hasTi,
            'matched_inbound_document_id' => $inbound?->getKey(),
            'matched_inbound_document' => $inbound,
            'in_transit_transferring_session_id' => $transfer?->getKey(),
            'in_transit_transferring_session' => $transfer,
            'current_session_id' => $currentSession?->getKey(),
        ];
    }

    public function epcHasTransactionInformation(Epc $epc): bool
    {
        return $this->hasTransactionInformation($epc);
    }

    private function hasTransactionInformation(Epc $epc): bool
    {
        $epcIds = [(int) $epc->getKey()];

        $parentIds = AggregationLink::query()
            ->open()
            ->where('child_epc_id', $epc->getKey())
            ->pluck('parent_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($parentIds as $parentId) {
            $epcIds[] = $parentId;
        }

        if ($epc->epc_type === 'sscc') {
            $childIds = AggregationLink::query()
                ->open()
                ->where('parent_epc_id', $epc->getKey())
                ->pluck('child_epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach ($childIds as $childId) {
                $epcIds[] = $childId;
            }
        }

        $epcIds = array_values(array_unique($epcIds));

        $events = EpcisEvent::query()
            ->select('epcis_events.id')
            ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id');
        LastGoodIngestProjection::constrainDocuments($events);
        $events
            ->whereColumn('epcis_events.ingest_generation', 'epcis_documents.ingest_generation')
            ->where(function ($query): void {
                $query->whereRaw("LOWER(epcis_events.biz_step) LIKE '%shipping%'")
                    ->orWhereRaw("LOWER(epcis_events.biz_step) LIKE '%commissioning%'");
            })
            ->whereExists(function ($query) use ($epcIds): void {
                $query->select(DB::raw(1))
                    ->from('event_epcs')
                    ->whereColumn('event_epcs.event_id', 'epcis_events.id')
                    ->whereIn('event_epcs.epc_id', $epcIds);
            });

        return $events->exists();
    }

    private function findUnmatchedInboundDocument(Epc $epc): ?EpcisDocument
    {
        $documents = EpcisDocument::query()
            ->where('direction', 'inbound');
        LastGoodIngestProjection::constrainDocuments($documents);
        $documents
            ->whereIn('id', function ($query) use ($epc): void {
                $query->select('epcis_events.document_id')
                    ->from('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
                    ->whereColumn('epcis_events.ingest_generation', 'epcis_documents.ingest_generation')
                    ->where('event_epcs.epc_id', $epc->getKey());
            })
            ->whereDoesntHave('receivingSession', function ($query): void {
                $query->whereIn('status', ['open', 'in_progress', 'completed']);
            })
            ->orderByDesc('id');

        return $documents->first();
    }

    private function findInTransitTransfer(Epc $epc): ?TransferringSession
    {
        $line = TransferringScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereIn('status', ['confirmed'])
            ->whereHas('session', function ($query): void {
                $query->where('status', 'in_transit');
            })
            ->orderByDesc('id')
            ->first();

        if ($line === null) {
            return null;
        }

        return TransferringSession::query()->find($line->transferring_session_id);
    }
}
