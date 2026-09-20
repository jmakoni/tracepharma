<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Epcis\EpcisDocument;
use App\Services\Receiving\ReceivingGate;
use App\Support\Copy\OperatorNouns;
use Illuminate\Database\Eloquent\Collection;

/**
 * Inbound EPCIS files the floor can still receive against.
 *
 * Same status rule as Start receiving, then drop fully received files and
 * files blocked by a document-wide exception. A hold on one serial does not
 * hide the file.
 */
final class EligibleEpcisReceiveDocuments
{
    public const LIMIT = 50;

    /**
     * Newest validated files considered before the PHP receive-status filter.
     * Bounded so a long received history cannot scan the whole catalog.
     */
    private const CANDIDATE_WINDOW = 200;

    /**
     * @return Collection<int, EpcisDocument>
     */
    public function list(): Collection
    {
        $gate = app(ReceivingGate::class);

        return $this->candidates()
            ->filter(function (EpcisDocument $document) use ($gate): bool {
                if ($document->isFloorReceived()) {
                    return false;
                }

                return $gate->documentBlockedByOpenException($document) === null;
            })
            ->take(self::LIMIT)
            ->values();
    }

    public function find(int $documentId): ?EpcisDocument
    {
        if ($documentId <= 0) {
            return null;
        }

        $document = EpcisDocument::query()
            ->inboundCatalog()
            ->whereKey($documentId)
            ->whereIn('status', $this->allowedStatuses())
            ->with(['receivingSession', 'inboundShipment', 'tradingPartner'])
            ->first();

        if (! $document instanceof EpcisDocument || $document->isFloorReceived()) {
            return null;
        }

        if (app(ReceivingGate::class)->documentBlockedByOpenException($document) !== null) {
            return null;
        }

        return $document;
    }

    public function rowTitle(EpcisDocument $document): string
    {
        $seller = $document->shippingPartiesSummary()['seller']['name'] ?? null;

        if (filled($seller)) {
            return (string) $seller;
        }

        return 'Inbound file #'.$document->getKey();
    }

    public function rowMeta(EpcisDocument $document): string
    {
        $status = $document->floorReceiveStatusLabel() === OperatorNouns::FLOOR_PARTIALLY_RECEIVED
            ? OperatorNouns::FLOOR_PARTIALLY_RECEIVED
            : 'Ready';

        $reference = $this->shipmentReference($document);

        return $reference !== null ? $reference.' · '.$status : $status;
    }

    private function shipmentReference(EpcisDocument $document): ?string
    {
        if (filled($document->asn_number)) {
            return 'ASN '.$document->asn_number;
        }

        if (filled($document->customer_po)) {
            return 'PO '.$document->customer_po;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function allowedStatuses(): array
    {
        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);

        return $requireValidated ? ['validated'] : ['parsed', 'validated'];
    }

    /**
     * @return Collection<int, EpcisDocument>
     */
    private function candidates(): Collection
    {
        return EpcisDocument::query()
            ->inboundCatalog()
            ->whereIn('status', $this->allowedStatuses())
            ->with(['receivingSession', 'inboundShipment', 'tradingPartner'])
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_WINDOW)
            ->get();
    }
}
