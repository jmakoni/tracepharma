<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class DscsaSectionReference
{
    public static function label(?string $typeCode): string
    {
        return match ($typeCode) {
            'MISSING_DSCSA_STATEMENT' => 'DSCSA §582.1(a)(6) Transaction Statement (TS)',
            'UNKNOWN_GTIN',
            'INVALID_GTIN_CHECK_DIGIT',
            'INVALID_SSCC_CHECK_DIGIT',
            'INVALID_EPC_URI',
            'MISSING_MANDATORY_FIELD',
            'BROKEN_AGGREGATION',
            'MISSING_PARENT',
            'MISSING_CHILDREN',
            'ORPHAN_SSCC' => 'DSCSA §582.1(a)(5) Transaction Information (TI)',
            'UNKNOWN_GLN',
            'MISSING_SOURCE_DESTINATION',
            'SBDH_SOURCE_OWNING_PARTY_MISMATCH' => 'DSCSA §582.1(a)(5) TI; Authorized Trading Partner identity',
            'VERIFICATION_FAILED',
            'SUSPECT_PRODUCT' => 'DSCSA §582.1(b) verification; suspect product handling',
            'RETURNS_NOT_LINKED' => 'DSCSA §582.2 product tracing',
            default => 'DSCSA product tracing and transaction document requirements',
        };
    }

    /**
     * @return list<string>
     */
    public static function receiverActions(?string $typeCode, bool $complianceHold = true): array
    {
        $actions = [];

        if ($complianceHold) {
            $actions[] = 'Shipment placed on COMPLIANCE HOLD — not Ready to receive';
            $actions[] = 'Product NOT accepted into inventory';
            $actions[] = 'Receiving acceptance BLOCKED until exception resolved';
        }

        return match ($typeCode) {
            'VERIFICATION_FAILED',
            'SUSPECT_PRODUCT' => array_merge($actions, [
                'Affected unit(s) quarantined — do not dispense',
                'Supplier NOT automatically notified — compliance review required',
            ]),
            'MISSING_DSCSA_STATEMENT' => array_merge($actions, [
                'Awaiting corrected TI/TS or re-transmitted EPCIS from supplier',
            ]),
            'RETURNS_NOT_LINKED' => array_merge($actions, [
                'No inventory movement until shipment identity is confirmed',
            ]),
            default => $actions,
        };
    }
}
