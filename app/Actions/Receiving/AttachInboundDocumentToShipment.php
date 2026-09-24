<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\RecordOperationalEpcisException;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attach an inbound EPCIS document to an ASN shipment (seller + ASN), emit soft
 * signal when a second file joins, and expand every open/in_progress receiving
 * session's expected parents (parallel-safe; oldest session claims first).
 *
 * When ASN is blank, uses synthetic key DOC:{document_uuid} so every inbound file
 * still groups to a shipment row (unique per document).
 */
final class AttachInboundDocumentToShipment
{
    public function __construct(
        private readonly RecordOperationalEpcisException $recordException,
        private readonly ExpandReceivingSessionExpectedParents $expandExpectedParents,
        private readonly ResolveAsnRootParentEpcIds $resolveAsnRootParentEpcIds,
    ) {}

    public function handle(EpcisDocument $document): ?InboundShipment
    {
        if (! Schema::hasTable('inbound_shipments')
            || ! Schema::hasColumn('epcis_documents', 'inbound_shipment_id')) {
            return null;
        }

        if ((string) ($document->direction ?? '') !== 'inbound') {
            return null;
        }

        $rawAsn = trim((string) ($document->asn_number ?? ''));
        $isRealAsn = $rawAsn !== '';
        $asn = $isRealAsn
            ? $rawAsn
            : 'DOC:'.trim((string) ($document->document_uuid ?? ''));

        if ($asn === 'DOC:' || $asn === '') {
            return null;
        }

        return DB::transaction(function () use ($document, $asn, $isRealAsn): ?InboundShipment {
            $document = EpcisDocument::query()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->inbound_shipment_id !== null) {
                return InboundShipment::query()->find($document->inbound_shipment_id);
            }

            $partnerId = $document->trading_partner_id !== null
                ? (int) $document->trading_partner_id
                : null;
            $partnerKey = InboundShipment::partnerKey($partnerId);
            $po = filled($document->customer_po) ? trim((string) $document->customer_po) : null;

            $shipment = InboundShipment::query()
                ->where('trading_partner_key', $partnerKey)
                ->where('asn_number', $asn)
                ->lockForUpdate()
                ->first();

            if ($shipment !== null) {
                $existingPo = filled($shipment->customer_po)
                    ? trim((string) $shipment->customer_po)
                    : null;

                // PO mismatch soft-exception only for real ASNs (not DOC: synthetic keys).
                if ($isRealAsn && $existingPo !== null && $po !== null && $existingPo !== $po) {
                    $this->recordException->handle(
                        $document,
                        'ASN_SHIPMENT_PO_MISMATCH',
                        sprintf(
                            'Inbound file ASN %s matches shipment #%d but customer PO %s conflicts with shipment PO %s; left ungrouped.',
                            $asn,
                            $shipment->getKey(),
                            $po,
                            $existingPo,
                        ),
                    );

                    return null;
                }

                if ($existingPo === null && $po !== null) {
                    $shipment->forceFill(['customer_po' => $po])->save();
                }
            } else {
                $shipment = InboundShipment::query()->create([
                    'trading_partner_id' => $partnerId,
                    'trading_partner_key' => $partnerKey,
                    'asn_number' => $asn,
                    'customer_po' => $po,
                    'status' => 'expected',
                    'document_count' => 0,
                ]);
            }

            $priorOtherCount = EpcisDocument::query()
                ->where('inbound_shipment_id', $shipment->getKey())
                ->whereKeyNot($document->getKey())
                ->count();

            $document->forceFill([
                'inbound_shipment_id' => $shipment->getKey(),
            ])->save();

            $documentCount = EpcisDocument::query()
                ->where('inbound_shipment_id', $shipment->getKey())
                ->count();

            $shipment->forceFill([
                'document_count' => $documentCount,
            ])->save();

            if ($priorOtherCount >= 1) {
                $this->recordException->handle(
                    $document,
                    'ASN_SHIPMENT_FILE_ADDED',
                    sprintf(
                        'Inbound file joined ASN %s shipment #%d (%d files total).',
                        $asn,
                        $shipment->getKey(),
                        $documentCount,
                    ),
                );

                $this->expandOpenReceivingSession($shipment->fresh(), $document->fresh());
            }

            return $shipment->fresh();
        });
    }

    private function expandOpenReceivingSession(InboundShipment $shipment, EpcisDocument $joiningDocument): void
    {
        if (! Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
            return;
        }

        // Expand every live session (not only newest). Oldest first so an active
        // session claims free addendum parents before an empty parallel opener.
        $sessions = ReceivingSession::query()
            ->where('inbound_shipment_id', $shipment->getKey())
            ->whereIn('status', ['open', 'in_progress'])
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $allowed = $requireValidated ? ['validated'] : ['parsed', 'validated'];

        // Only expand from documents that are already receiving-eligible. Joining
        // files attach during enrich (pre-validate); their roots are added once validated.
        $rootIds = $this->resolveAsnRootParentEpcIds->resolveUnionRootParentEpcIds($shipment, $allowed);
        if (in_array((string) ($joiningDocument->status ?? ''), $allowed, true)) {
            $rootIds = array_values(array_unique(array_merge(
                $rootIds,
                $this->resolveAsnRootParentEpcIds->resolveRootParentEpcIds($joiningDocument),
            )));
        }

        foreach ($sessions as $session) {
            $this->expandExpectedParents->handle($session, $rootIds);
        }

        // Order status vocab is expected|open|complete|cancelled — do not mutate to legacy 'receiving'.
        // Map any stale 'receiving' row to 'open' when expanding.
        if ((string) ($shipment->status ?? '') === 'receiving') {
            $shipment->forceFill(['status' => 'open'])->save();
        }
    }

    /**
     * After a joining ASN file becomes receiving-eligible, sync expected inbound
     * lines then expand every open session so claims can succeed.
     */
    public function expandOpenSessionAfterDocumentEligible(EpcisDocument $document): void
    {
        if (! Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
            // Still sync expected lines even when sessions column is absent.
            $this->syncExpectedLinesIfEligible($document);

            return;
        }

        if ($document->inbound_shipment_id === null) {
            $this->syncExpectedLinesIfEligible($document);

            return;
        }

        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $allowed = $requireValidated ? ['validated'] : ['parsed', 'validated'];
        if (! in_array((string) ($document->status ?? ''), $allowed, true)) {
            return;
        }

        // Sync before expand so InboundExpectedLineClaims can claim addendum parents.
        $this->syncExpectedLinesIfEligible($document);

        $shipment = InboundShipment::query()->find($document->inbound_shipment_id);
        if ($shipment === null) {
            return;
        }

        $this->expandOpenReceivingSession($shipment, $document->fresh() ?? $document);
    }

    private function syncExpectedLinesIfEligible(EpcisDocument $document): void
    {
        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $allowed = $requireValidated ? ['validated'] : ['parsed', 'validated'];
        if (! in_array((string) ($document->status ?? ''), $allowed, true)) {
            return;
        }

        app(SyncInboundExpectedLinesFromDocument::class)->handle(
            $document->fresh() ?? $document,
            allowCorrection: true,
        );
    }
}
