<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\EpcisDocument;
use Illuminate\Support\Facades\Schema;

/**
 * Attach existing inbound documents with ASN to shipments (idempotent).
 */
final class BackfillInboundShipments
{
    public function __construct(
        private readonly AttachInboundDocumentToShipment $attach,
    ) {}

    /**
     * @return array{processed: int, attached: int, skipped: int}
     */
    public function handle(?int $documentId = null, int $limit = 500): array
    {
        if (! Schema::hasTable('inbound_shipments')
            || ! Schema::hasColumn('epcis_documents', 'inbound_shipment_id')) {
            return ['processed' => 0, 'attached' => 0, 'skipped' => 0];
        }

        $query = EpcisDocument::query()
            ->where('direction', 'inbound')
            ->whereNotNull('asn_number')
            ->where('asn_number', '!=', '')
            ->whereNull('inbound_shipment_id')
            ->orderBy('id');

        if ($documentId !== null) {
            $query->whereKey($documentId);
        }

        $processed = 0;
        $attached = 0;
        $skipped = 0;

        foreach ($query->cursor() as $document) {
            if ($processed >= $limit) {
                break;
            }

            $processed++;
            $shipment = $this->attach->handle($document);
            if ($shipment !== null) {
                $attached++;
            } else {
                $skipped++;
            }
        }

        return compact('processed', 'attached', 'skipped');
    }
}
