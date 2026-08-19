<?php

namespace App\Support\Tracing;

use App\Models\Epcis\Epc;
use App\Support\Gs1\EpcBarcodeDisplay;

/**
 * Query params for the Verify Product page from an SGTIN EPC.
 *
 * @phpstan-type VerifyParams array{barcode: string, gtin: ?string, serial: ?string}
 */
final class VerifyUrlParams
{
    /**
     * @return VerifyParams|null
     */
    public static function forEpc(?Epc $epc): ?array
    {
        if ($epc === null) {
            return null;
        }

        if ($epc->epc_type !== 'sgtin' || ! filled($epc->gtin14) || ! filled($epc->serial_number)) {
            return null;
        }

        return [
            'barcode' => EpcBarcodeDisplay::forEpc($epc),
            'gtin' => $epc->gtin14,
            'serial' => $epc->serial_number,
        ];
    }
}
