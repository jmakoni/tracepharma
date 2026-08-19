<?php

namespace App\Support\Gs1;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use DateTimeInterface;

/**
 * Operator-facing barcode labels for an EPC.
 *
 * SSCC stays SSCC-18 / AI 00. SGTIN concatenates 01+21 with ILMD expiry/lot
 * when present; stored {@see Epc::$ai_01_21} is unchanged.
 */
final class EpcBarcodeDisplay
{
    public static function forEpc(Epc $epc): string
    {
        if (filled($epc->sscc18)) {
            return (string) $epc->sscc18;
        }

        if ($epc->epc_type === 'sscc' && filled($epc->ai_00)) {
            return (string) $epc->ai_00;
        }

        $gtin14 = filled($epc->gtin14) ? (string) $epc->gtin14 : null;
        $serial = filled($epc->serial_number) ? (string) $epc->serial_number : null;

        if ($gtin14 === null || $serial === null) {
            $identity = filled($epc->ai_01_21)
                ? ElementString::sgtinIdentity((string) $epc->ai_01_21)
                : null;

            $gtin14 = $identity['gtin14'] ?? null;
            $serial = $identity['serial'] ?? null;
        }

        if ($gtin14 !== null && $serial !== null) {
            [$lot, $expiry] = self::lotExpiryAis($epc);

            return ElementString::encodeSgtin($gtin14, $serial, $lot, $expiry);
        }

        if (filled($epc->ai_01_21)) {
            return (string) $epc->ai_01_21;
        }

        if (filled($epc->ai_00)) {
            return (string) $epc->ai_00;
        }

        return (string) $epc->epc_uri;
    }

    /**
     * @return array{0: ?string, 1: ?string} Lot number, expiry YYMMDD
     */
    public static function lotExpiryAis(Epc $epc): array
    {
        $ilmd = self::ilmd($epc);

        return [
            filled($ilmd?->lot_number) ? (string) $ilmd->lot_number : null,
            self::expiryYymmdd($ilmd),
        ];
    }

    private static function ilmd(Epc $epc): ?EpcIlmd
    {
        if ($epc->relationLoaded('ilmd')) {
            $ilmd = $epc->getRelation('ilmd');

            return $ilmd instanceof EpcIlmd ? $ilmd : null;
        }

        if (! $epc->exists) {
            return null;
        }

        $ilmd = $epc->ilmd;

        return $ilmd instanceof EpcIlmd ? $ilmd : null;
    }

    private static function expiryYymmdd(?EpcIlmd $ilmd): ?string
    {
        if ($ilmd === null) {
            return null;
        }

        $date = $ilmd->expiry_date;

        if ($date instanceof DateTimeInterface) {
            return $date->format('ymd');
        }

        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1) {
            return substr($date, 2, 2).substr($date, 5, 2).substr($date, 8, 2);
        }

        return null;
    }
}
