<?php

declare(strict_types=1);

namespace App\Support\Receiving;

/**
 * Canonical receive-desk exception types. OS&D names stay OS&D; DSCSA
 * product/data names stay DSCSA. PARTIAL_SHIPMENT_UNDECLARED is a reason, not a dock type.
 */
final class ReceiveExceptionTypes
{
    public const SHORTAGE = 'SHORTAGE';

    public const OVERAGE = 'OVERAGE';

    public const DAMAGED = 'DAMAGED';

    public const PRODUCT_NO_DATA = 'PRODUCT_NO_DATA';

    public const DATA_NO_PRODUCT = 'DATA_NO_PRODUCT';

    public const AGGREGATION_BREAK = 'AGGREGATION_BREAK';

    public const PI_MISMATCH = 'PI_MISMATCH';

    public const DUPLICATE_SERIAL = 'DUPLICATE_SERIAL';

    public const WRONG_DESTINATION = 'WRONG_DESTINATION';

    public const LATE_FAILED_EPCIS = 'LATE_FAILED_EPCIS';

    public const WRONG_ITEM = 'WRONG_ITEM';

    public const REFUSED = 'REFUSED';

    public const REASON_UNDECLARED_PARTIAL = 'PARTIAL_SHIPMENT_UNDECLARED';

    /**
     * Open session cases that hard-block Complete (not short-close DATA_NO_PRODUCT / SHORTAGE).
     *
     * @var list<string>
     */
    public const HARD_BLOCK_COMPLETE = [
        self::PRODUCT_NO_DATA,
        self::AGGREGATION_BREAK,
        self::DAMAGED,
        self::PI_MISMATCH,
        self::DUPLICATE_SERIAL,
        self::WRONG_DESTINATION,
        self::OVERAGE,
        self::LATE_FAILED_EPCIS,
        self::WRONG_ITEM,
    ];

    /**
     * Short-close with leftover expected may complete only when one of these is open.
     *
     * @var list<string>
     */
    public const SHORT_CLOSE_REQUIRED = [
        self::DATA_NO_PRODUCT,
        self::SHORTAGE,
    ];

    /**
     * @return array<string, string>
     */
    public static function typeMap(): array
    {
        return [
            self::SHORTAGE => 'OS&D shortage (dock)',
            self::OVERAGE => 'OS&D overage (dock)',
            self::DAMAGED => 'OS&D damaged (dock)',
            self::PRODUCT_NO_DATA => 'DSCSA product, no matching inbound serial/file',
            self::DATA_NO_PRODUCT => 'DSCSA inbound serial/file, no physical scan',
            self::AGGREGATION_BREAK => 'Sealed parent with no inbound aggregation children',
            self::PI_MISMATCH => 'Scanned 2D GTIN/serial/lot/expiry ≠ inbound EPCIS PI',
            self::DUPLICATE_SERIAL => 'Serial already received, shipped, or sold',
            self::WRONG_DESTINATION => 'Inbound ship-to SGLN ≠ this receive site',
            self::LATE_FAILED_EPCIS => 'Missing, late, or schema-rejected inbound EPCIS',
            self::WRONG_ITEM => 'Scanned GTIN/lot ≠ ASN/order line product identity',
            self::REFUSED => 'Receive cancelled after product presented — serials not received',
            self::REASON_UNDECLARED_PARTIAL => 'Reason only — undeclared partial (not a dock type)',
        ];
    }
}
