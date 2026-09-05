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
 * signal when a second file joins, and expand any open receiving session's expected parents.
 */
final class AttachInboundDocumentToShipment
{
    public function __construct(
        private readonly RecordOperationalEpcisException $recordException,
        private readonly ExpandReceivingSessionExpectedParents $expandExpectedParents,
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

        $asn = trim((string) ($document->asn_number ?? ''));
        if ($asn === '') {
            return null;
        }

        return DB::transaction(function () use ($document, $asn): ?InboundShipment {
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

                if ($existingPo !== null && $po !== null && $existingPo !== $po) {
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
                    'status' => 'open',
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

        $session = ReceivingSession::query()
            ->where('inbound_shipment_id', $shipment->getKey())
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            return;
        }

        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $allowed = $requireValidated ? ['validated'] : ['parsed', 'validated'];
        $opener = app(OpenReceivingSessionFromDocument::class);

        // Only expand from documents that are already receiving-eligible. Joining
        // files attach during enrich (pre-validate); their roots are added once validated.
        $rootIds = $opener->resolveUnionRootParentEpcIds($shipment, $allowed);
        if (in_array((string) ($joiningDocument->status ?? ''), $allowed, true)) {
            $rootIds = array_values(array_unique(array_merge(
                $rootIds,
                $opener->resolveRootParentEpcIds($joiningDocument),
            )));
        }

        $this->expandExpectedParents->handle($session, $rootIds);

        if ($shipment->status === 'open') {
            $shipment->forceFill(['status' => 'receiving'])->save();
        }
    }

    /**
     * After a joining ASN file becomes receiving-eligible, expand any open session.
     */
    public function expandOpenSessionAfterDocumentEligible(EpcisDocument $document): void
    {
        if (! Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
            return;
        }

        if ($document->inbound_shipment_id === null) {
            return;
        }

        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $allowed = $requireValidated ? ['validated'] : ['parsed', 'validated'];
        if (! in_array((string) ($document->status ?? ''), $allowed, true)) {
            return;
        }

        $shipment = InboundShipment::query()->find($document->inbound_shipment_id);
        if ($shipment === null) {
            return;
        }

        $this->expandOpenReceivingSession($shipment, $document);
    }
}
