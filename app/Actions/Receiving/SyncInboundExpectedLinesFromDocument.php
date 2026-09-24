<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\RecordOperationalEpcisException;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent upsert of shipment expected lines from an inbound EPCIS document.
 *
 * Parents from shipping/aggregation roots; children from open AggregationLink rows.
 * Class-level childQuantityList (no child EPCs) stores expected_class_qty on the parent
 * — never invents fake serials.
 *
 * Second+ file on the same ASN (vs shipment expected|confirmed lines), only when
 * {@see handle()} is called with $allowCorrection (ingest path):
 * - ADDENDUM (any new EPCs) → union only
 * - CORRECTION (no new EPCs; file proper subset) → cancel expected absent from file
 * - Equal set / open-session re-sync → upsert only, no prune
 *
 * Correction is evaluated at most once per document per process so ProcessEpcisDocument's
 * double Sync (expand + explicit) cannot reclassify an addendum as a correction.
 */
final class SyncInboundExpectedLinesFromDocument
{
    /** @var array<int, true> */
    private static array $correctionEvaluatedForDocument = [];

    public function __construct(
        private readonly AttachInboundDocumentToShipment $attachInboundDocumentToShipment,
        private readonly RecordOperationalEpcisException $recordException,
        private readonly ResolveAsnRootParentEpcIds $resolveAsnRootParentEpcIds,
    ) {}

    public function handle(
        EpcisDocument $document,
        ?InboundShipment $shipment = null,
        bool $allowCorrection = false,
    ): ?InboundShipment {
        if (! Schema::hasTable('inbound_expected_lines')
            || ! Schema::hasColumn('epcis_documents', 'inbound_shipment_id')) {
            return $shipment;
        }

        if ((string) ($document->direction ?? '') !== 'inbound') {
            return $shipment;
        }

        if ($shipment === null && $document->inbound_shipment_id === null) {
            $this->attachInboundDocumentToShipment->handle($document);
            $document = $document->refresh();
        }

        $shipment ??= $document->inbound_shipment_id !== null
            ? InboundShipment::query()->find((int) $document->inbound_shipment_id)
            : null;

        if ($shipment === null) {
            return null;
        }

        $parentIds = $this->resolveAsnRootParentEpcIds->resolveRootParentEpcIds($document);
        $now = now();
        $shipmentId = (int) $shipment->getKey();
        $documentId = (int) $document->getKey();
        $isSubsequentFile = $shipment->documents()->whereKeyNot($document->getKey())->exists();
        $parentSource = $isSubsequentFile ? 'addendum' : 'epcis_ship';

        $childLinks = $this->childLinksForDocument($document, $parentIds);
        $fileEpcIds = array_values(array_unique(array_map(
            'intval',
            array_merge(
                $parentIds,
                $childLinks->pluck('child_epc_id')->all(),
            ),
        )));

        $classification = 'initial';
        if ($isSubsequentFile && $allowCorrection) {
            if (! isset(self::$correctionEvaluatedForDocument[$documentId])) {
                self::$correctionEvaluatedForDocument[$documentId] = true;
                $classification = $this->classifyAgainstShipment($shipmentId, $fileEpcIds);
            } else {
                $classification = 'resync';
            }
        } elseif ($isSubsequentFile) {
            $classification = 'resync';
        }

        foreach ($parentIds as $parentEpcId) {
            $this->upsertExpectedLine(
                $shipmentId,
                (int) $parentEpcId,
                null,
                'parent',
                $parentSource,
                $now,
            );
        }

        if ($parentIds === []) {
            $shipment->refreshRollups();

            return $shipment->fresh();
        }

        $parentsWithChildren = [];
        foreach ($childLinks as $link) {
            $parentsWithChildren[(int) $link->parent_epc_id] = true;
            $this->upsertExpectedLine(
                $shipmentId,
                (int) $link->child_epc_id,
                (int) $link->parent_epc_id,
                'child',
                'epcis_aggregation',
                $now,
            );
        }

        $this->applyClassQuantityExpectations($document, $shipmentId, $parentIds, $parentsWithChildren);

        if ($classification === 'correction') {
            $pruned = $this->pruneExpectedAbsentFromFile($shipmentId, $fileEpcIds, $now);
            if ($pruned !== []) {
                $this->recordException->handle(
                    $document,
                    'ASN_SHIPMENT_CORRECTED',
                    sprintf(
                        'Inbound file corrected ASN %s shipment #%d: cancelled %d expected line(s) absent from this file (confirmed lines unchanged).',
                        (string) $shipment->asn_number,
                        $shipmentId,
                        count($pruned),
                    ),
                );
                $this->pruneOpenSessionExpectedLines($shipmentId, $pruned);
            }
        }

        $shipment->refreshRollups();

        return $shipment->fresh();
    }

    /**
     * @param  list<int>  $fileEpcIds
     * @return 'addendum'|'correction'|'resync'
     */
    private function classifyAgainstShipment(int $shipmentId, array $fileEpcIds): string
    {
        $existing = InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->whereIn('status', ['expected', 'confirmed'])
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $existingSet = array_fill_keys($existing, true);
        $hasNew = false;
        foreach ($fileEpcIds as $epcId) {
            if (! isset($existingSet[$epcId])) {
                $hasNew = true;
                break;
            }
        }

        if ($hasNew) {
            return 'addendum';
        }

        if ($fileEpcIds === [] || $existing === []) {
            return 'resync';
        }

        // No new EPCs: correction only when this file is a proper subset of the
        // current expected|confirmed set (partner retracted unconfirmed serials).
        // Equal set = same-serials re-send — upsert only.
        foreach ($existing as $epcId) {
            if (! in_array($epcId, $fileEpcIds, true)) {
                return 'correction';
            }
        }

        return 'resync';
    }

    /**
     * @param  list<int>  $fileEpcIds
     * @return list<int> pruned epc ids
     */
    private function pruneExpectedAbsentFromFile(int $shipmentId, array $fileEpcIds, mixed $now): array
    {
        $fileSet = array_fill_keys($fileEpcIds, true);

        $toCancel = InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->where('status', 'expected')
            ->get();

        $pruned = [];
        foreach ($toCancel as $line) {
            $epcId = (int) $line->epc_id;
            if (isset($fileSet[$epcId])) {
                continue;
            }

            $line->forceFill([
                'status' => 'cancelled',
                'updated_at' => $now,
            ])->save();
            $pruned[] = $epcId;
        }

        return $pruned;
    }

    /**
     * @param  list<int>  $prunedEpcIds
     */
    private function pruneOpenSessionExpectedLines(int $shipmentId, array $prunedEpcIds): void
    {
        if ($prunedEpcIds === [] || ! Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
            return;
        }

        $sessions = ReceivingSession::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->whereIn('status', ['open', 'in_progress'])
            ->get();

        foreach ($sessions as $session) {
            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'expected')
                ->whereIn('epc_id', $prunedEpcIds)
                ->delete();

            $parentCount = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'parent')
                ->count();
            $childCount = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->count();
            $confirmedParent = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'parent')
                ->where('status', 'confirmed')
                ->count();
            $confirmedChild = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->where('status', 'confirmed')
                ->count();

            $session->forceFill([
                'expected_parent_count' => $parentCount,
                'expected_child_count' => $childCount,
                'confirmed_parent_count' => $confirmedParent,
                'confirmed_child_count' => $confirmedChild,
            ])->save();
        }
    }

    /**
     * @param  list<int>  $parentIds
     * @return Collection<int, AggregationLink>
     */
    private function childLinksForDocument(EpcisDocument $document, array $parentIds)
    {
        if ($parentIds === []) {
            return collect();
        }

        return AggregationLink::query()
            ->whereNull('valid_to')
            ->whereIn('parent_epc_id', $parentIds)
            ->whereIn('established_by_event_id', function ($query) use ($document): void {
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
            })
            ->get(['parent_epc_id', 'child_epc_id']);
    }

    /**
     * @param  list<int>  $parentIds
     * @param  array<int, true>  $parentsWithChildren
     */
    private function applyClassQuantityExpectations(
        EpcisDocument $document,
        int $shipmentId,
        array $parentIds,
        array $parentsWithChildren,
    ): void {
        if (! Schema::hasTable('event_quantities')) {
            return;
        }

        foreach ($parentIds as $parentEpcId) {
            if (isset($parentsWithChildren[$parentEpcId])) {
                continue;
            }

            $qty = DB::table('event_quantities')
                ->whereIn('event_id', function ($query) use ($document): void {
                    $query->select('id')
                        ->from('epcis_events')
                        ->where('document_id', $document->getKey());
                })
                ->whereIn('role', ['childQuantityList', 'quantityList'])
                ->sum('quantity');

            if ($qty === null || (float) $qty <= 0) {
                continue;
            }

            $line = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('epc_id', $parentEpcId)
                ->first();

            if ($line === null || in_array($line->status, ['confirmed', 'cancelled'], true)) {
                continue;
            }

            if ($line->expected_class_qty === null) {
                $line->forceFill(['expected_class_qty' => (int) round((float) $qty)])->save();
            }
        }
    }

    private function upsertExpectedLine(
        int $shipmentId,
        int $epcId,
        ?int $parentEpcId,
        string $lineRole,
        string $source,
        mixed $now,
    ): void {
        $existing = InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->where('epc_id', $epcId)
            ->first();

        if ($existing === null) {
            InboundExpectedLine::query()->create([
                'inbound_shipment_id' => $shipmentId,
                'epc_id' => $epcId,
                'parent_epc_id' => $parentEpcId,
                'line_role' => $lineRole,
                'status' => 'expected',
                'source' => $source,
            ]);

            return;
        }

        // Never reset confirmed/cancelled/unexpected; only fill missing parent linkage.
        if (in_array($existing->status, ['confirmed', 'cancelled', 'unexpected'], true)) {
            return;
        }

        $updates = [];
        if ($existing->parent_epc_id === null && $parentEpcId !== null) {
            $updates['parent_epc_id'] = $parentEpcId;
        }
        if ($existing->line_role !== $lineRole && $lineRole === 'parent') {
            $updates['line_role'] = $lineRole;
        }
        if ($existing->source === null) {
            $updates['source'] = $source;
        }
        if ($updates !== []) {
            $updates['updated_at'] = $now;
            $existing->forceFill($updates)->save();
        }
    }
}
