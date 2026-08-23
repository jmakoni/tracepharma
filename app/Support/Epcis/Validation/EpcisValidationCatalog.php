<?php

namespace App\Support\Epcis\Validation;

use Database\Seeders\ExceptionTypeSeeder;

/**
 * Canonical set of exception type codes owned by GS1/DSCSA validation, mirrored
 * from {@see ExceptionTypeSeeder}. Every code here must exist
 * (active) in the exception_types catalog.
 */
final class EpcisValidationCatalog
{
    /**
     * @var list<string>
     */
    public const CODES = [
        // Identifier & Master Data
        'DUPLICATE_SERIAL',
        'SERIAL_ALREADY_COMMISSIONED',
        'UNKNOWN_GTIN',
        'INVALID_GTIN_CHECK_DIGIT',
        'INVALID_SSCC_CHECK_DIGIT',
        'UNKNOWN_GLN',
        'INVALID_COMPANY_PREFIX',
        'LEADING_ZERO_STRIPPED',
        'GTIN_SERIAL_MISMATCH',
        'INVALID_EPC_URI',
        'UNSUPPORTED_EPC_TYPE',

        // Event Structure & Content
        'MISSING_MANDATORY_FIELD',
        'INVALID_BIZSTEP',
        'INVALID_DISPOSITION',
        'FUTURE_EVENT_TIME',
        'STALE_EVENT',
        'INVALID_ACTION',
        'DELETE_WITHOUT_PRIOR_ADD',
        'MISSING_DSCSA_STATEMENT',
        'INVALID_EXTENSION_NAMESPACE', // hook-only (see OPERATIONAL_HOOK_CODES) until an XML namespace scan exists
        'MIXED_PACKAGING_LEVELS',

        // Aggregation & Hierarchy
        'BROKEN_AGGREGATION',
        'MISSING_PARENT',
        'MISSING_CHILDREN',
        'AGGREGATION_QUANTITY_MISMATCH',
        'MULTIPLE_PARENTS',
        'ORPHAN_SSCC',
        'HIERARCHY_DEPTH_EXCEEDED',
        'PACKAGING_TYPE_CONFLICT',
        'DEAGGREGATION_WITHOUT_PRIOR',

        // Quantity, Lot & Expiry
        'LOT_MISMATCH',
        'QUANTITY_MISMATCH',
        'MISSING_EXPIRY',
        'EXPIRED_PRODUCT_SHIPPED',
        'MIXED_EXPIRY_SAME_LOT',
        'PARTIAL_SHIPMENT_UNDECLARED',
        'OVER_SHIPMENT',

        // Timing & Sequence
        'TIMING_INVERSION',
        'COMMISSION_AFTER_SHIP',
        'EVENTS_OUT_OF_ORDER',
        'SHIP_BEFORE_COMMISSION',
        'DECOMMISSION_AFTER_SHIP',

        // Transmission & Partner
        'PARTNER_REJECTED_FILE',
        'MISSING_MDN',
        'LATE_MDN',
        'DUPLICATE_TRANSMISSION',
        'FILE_SIZE_EXCEEDED',
        'ENCODING_ERROR',
        'MISSING_SOURCE_DESTINATION',
        'MISSING_BIZ_TRANSACTION',

        // Process & DSCSA Compliance
        'MISSING_COMMISSIONING',
        'SERIAL_SHIPPED_NOT_COMMISSIONED',
        'DECOMMISSIONED_SERIAL_SHIPPED',
        'SUSPECT_PRODUCT',
        'VERIFICATION_FAILED',
        'RETURNS_NOT_LINKED',
        'DROP_SHIPMENT_INDICATOR_MISSING',
        'OWNERSHIP_TRANSFER_UNCLEAR',

        // System / Operational
        'L2_L3_RECONCILIATION_FAILURE',
        'L3_TRANSMISSION_FAILURE',
        'AUTO_DECOMMISSION_FAILED',
        'MASTER_DATA_SYNC_LAG',
        'INGESTION_PARSE_ERROR',
        'INTERNAL_VALIDATION_FAILED',
        'FINDINGS_TRUNCATED',

        // Fallback
        'UNCLASSIFIED',
    ];

    /**
     * Codes produced only by operational jobs/hooks — not cleared/rewritten by ValidateEpcis12Document.
     *
     * @var list<string>
     */
    public const OPERATIONAL_HOOK_CODES = [
        'PARTNER_REJECTED_FILE',
        'MISSING_MDN',
        'LATE_MDN',
        'SUSPECT_PRODUCT',
        'VERIFICATION_FAILED',
        'L2_L3_RECONCILIATION_FAILURE',
        'L3_TRANSMISSION_FAILURE',
        'AUTO_DECOMMISSION_FAILED',
        'MASTER_DATA_SYNC_LAG',
        'UNCLASSIFIED',
        'LEADING_ZERO_STRIPPED',
        'ENCODING_ERROR',
        'PACKAGING_TYPE_CONFLICT',
        'GTIN_SERIAL_MISMATCH',
        'PARTIAL_SHIPMENT_UNDECLARED',
        'OVER_SHIPMENT',
        'INVALID_EXTENSION_NAMESPACE',
        'LOT_MISMATCH',
        'QUANTITY_MISMATCH',
    ];

    public static function isOwned(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::CODES, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::CODES;
    }

    /**
     * Exception types cleared and rewritten on each validation pass.
     *
     * @return list<string>
     */
    public static function clearableCodes(): array
    {
        return array_values(array_diff(self::CODES, self::OPERATIONAL_HOOK_CODES));
    }
}
