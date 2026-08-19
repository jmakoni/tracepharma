<?php

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisDocument;

final class ShipmentReference
{
    public static function po(?EpcisDocument $document): string
    {
        if ($document === null) {
            return '—';
        }

        if (filled($document->customer_po)) {
            return (string) $document->customer_po;
        }

        if (filled($document->asn_number)) {
            return (string) $document->asn_number;
        }

        $filename = (string) ($document->original_filename ?? '');
        if (preg_match('/^(\d{6,})_/', $filename, $matches) === 1) {
            return $matches[1];
        }

        return 'Not in file';
    }
}
