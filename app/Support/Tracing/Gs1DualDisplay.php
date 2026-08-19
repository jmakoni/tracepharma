<?php

namespace App\Support\Tracing;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;

/**
 * GS1 "dual display" helpers for the Asset Tracking view.
 *
 * Given a resolved {@see Epc} (or raw identity keys from
 * {@see ElementString} / {@see ResolveEpcFromScan}),
 * produce a human-readable primary identifier, the GS1 element-string barcode
 * form, and the Pure Identity URN. Prefers values already materialized on the
 * Epc model (ai_00, ai_01_21, sscc18, epc_uri) over re-deriving them.
 */
final class Gs1DualDisplay
{
    /**
     * @return array{primary: string, gs1_barcode: string, urn: string}
     */
    public static function forEpc(Epc $epc): array
    {
        if ($epc->epc_type === 'sscc') {
            return self::sscc(
                filled($epc->sscc18) ? (string) $epc->sscc18 : null,
                filled($epc->ai_00) ? (string) $epc->ai_00 : null,
                filled($epc->epc_uri) ? (string) $epc->epc_uri : null,
            );
        }

        if ($epc->epc_type === 'sgtin') {
            [$lot, $expiry] = EpcBarcodeDisplay::lotExpiryAis($epc);

            return self::sgtin(
                filled($epc->gtin14) ? (string) $epc->gtin14 : null,
                filled($epc->serial_number) ? (string) $epc->serial_number : null,
                filled($epc->epc_uri) ? (string) $epc->epc_uri : null,
                $lot,
                $expiry,
            );
        }

        $uri = filled($epc->epc_uri) ? (string) $epc->epc_uri : null;

        return [
            'primary' => $uri ?? '—',
            'gs1_barcode' => '—',
            'urn' => $uri ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $identity  Keys as produced by ElementString/ResolveEpcFromScan:
     *                                          sscc18/ai_00, or gtin14/serial(_number)/ai_01_21, plus optional
     *                                          epc_uri, lot_number, and expiry_yymmdd.
     * @return array{primary: string, gs1_barcode: string, urn: string}
     */
    public static function forIdentity(array $identity): array
    {
        $uri = filled($identity['epc_uri'] ?? null) ? (string) $identity['epc_uri'] : null;

        if (filled($identity['sscc18'] ?? null) || filled($identity['ai_00'] ?? null)) {
            return self::sscc(
                filled($identity['sscc18'] ?? null) ? (string) $identity['sscc18'] : null,
                filled($identity['ai_00'] ?? null) ? (string) $identity['ai_00'] : null,
                $uri,
            );
        }

        $serial = $identity['serial'] ?? $identity['serial_number'] ?? null;

        if (filled($identity['gtin14'] ?? null) && filled($serial)) {
            return self::sgtin(
                (string) $identity['gtin14'],
                (string) $serial,
                $uri,
                filled($identity['lot_number'] ?? null) ? (string) $identity['lot_number'] : null,
                filled($identity['expiry_yymmdd'] ?? null) ? (string) $identity['expiry_yymmdd'] : null,
            );
        }

        return [
            'primary' => $uri ?? '—',
            'gs1_barcode' => '—',
            'urn' => $uri ?? '',
        ];
    }

    /**
     * @return array{primary: string, gs1_barcode: string, urn: string}
     */
    private static function sscc(?string $sscc18, ?string $ai00, ?string $uri): array
    {
        $sscc18 ??= filled($ai00) ? substr((string) $ai00, 2) : null;

        return [
            'primary' => $sscc18 ?? ($uri ?? '—'),
            'gs1_barcode' => filled($sscc18) ? '(00)'.$sscc18 : '—',
            'urn' => $uri ?? '',
        ];
    }

    /**
     * @return array{primary: string, gs1_barcode: string, urn: string}
     */
    private static function sgtin(
        ?string $gtin14,
        ?string $serial,
        ?string $uri,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
    ): array {
        if (! filled($gtin14) || ! filled($serial)) {
            return [
                'primary' => $uri ?? '—',
                'gs1_barcode' => '—',
                'urn' => $uri ?? '',
            ];
        }

        return [
            'primary' => $gtin14.' · '.$serial,
            'gs1_barcode' => ElementString::encodeSgtin($gtin14, $serial, $lot, $expiryYymmdd),
            'urn' => $uri ?? '',
        ];
    }
}
