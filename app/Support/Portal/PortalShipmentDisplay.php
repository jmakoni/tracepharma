<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Models\Epcis\EpcisDocument;
use App\Support\Epcis\EpcisDocumentXmlDownload;

final class PortalShipmentDisplay
{
    public static function label(EpcisDocument $document): string
    {
        if (filled($document->customer_po)) {
            return (string) $document->customer_po;
        }

        if (filled($document->asn_number)) {
            return 'ASN '.(string) $document->asn_number;
        }

        if (filled($document->document_uuid)) {
            return 'Shipment '.substr((string) $document->document_uuid, 0, 8);
        }

        return 'Shipment #'.$document->getKey();
    }

    public static function subtitle(EpcisDocument $document): ?string
    {
        $parts = [];

        if (filled($document->customer_po) && filled($document->asn_number)) {
            $parts[] = 'ASN '.(string) $document->asn_number;
        }

        if (filled($document->document_uuid)) {
            $parts[] = substr((string) $document->document_uuid, 0, 8);
        }

        return $parts !== [] ? implode(' · ', $parts) : null;
    }

    public static function reportsAvailable(EpcisDocument $document): bool
    {
        if (in_array($document->status, ['parsed', 'validated', 'generated'], true)) {
            return true;
        }

        // Published outbound shipments may retain validation errors while still carrying TI/TS.
        return $document->status === 'error'
            && EpcisDocumentXmlDownload::available($document);
    }
}
