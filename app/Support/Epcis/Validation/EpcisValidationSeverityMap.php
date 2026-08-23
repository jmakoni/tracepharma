<?php

namespace App\Support\Epcis\Validation;

use Database\Seeders\ExceptionTypeSeeder;

/**
 * Maps exception type codes to a severity string ('critical'|'error'|'warning'|'info')
 * given a validation context. Base table mirrors the ExceptionSeverity intent used
 * by {@see ExceptionTypeSeeder} (Critical→critical, High→error,
 * Medium→warning), with explicit demo-safe / GS1 US R1.3 overrides layered on top.
 */
final class EpcisValidationSeverityMap
{
    /**
     * Default severity per code, mirroring ExceptionTypeSeeder::default_severity intent.
     *
     * @var array<string, string>
     */
    private const DEFAULT_SEVERITY = [
        // Identifier & Master Data
        'DUPLICATE_SERIAL' => 'critical',
        'SERIAL_ALREADY_COMMISSIONED' => 'critical',
        'UNKNOWN_GTIN' => 'error',
        'INVALID_GTIN_CHECK_DIGIT' => 'error',
        'INVALID_SSCC_CHECK_DIGIT' => 'error',
        'UNKNOWN_GLN' => 'error',
        'INVALID_COMPANY_PREFIX' => 'error',
        'LEADING_ZERO_STRIPPED' => 'warning',
        'GTIN_SERIAL_MISMATCH' => 'error',
        'INVALID_EPC_URI' => 'error',
        'UNSUPPORTED_EPC_TYPE' => 'warning',

        // Event Structure & Content
        'MISSING_MANDATORY_FIELD' => 'error',
        'INVALID_BIZSTEP' => 'warning',
        'INVALID_DISPOSITION' => 'warning',
        'FUTURE_EVENT_TIME' => 'error',
        'STALE_EVENT' => 'warning',
        'INVALID_ACTION' => 'warning',
        'DELETE_WITHOUT_PRIOR_ADD' => 'error',
        'MISSING_DSCSA_STATEMENT' => 'error',
        'INVALID_EXTENSION_NAMESPACE' => 'warning',
        'MIXED_PACKAGING_LEVELS' => 'warning',

        // Aggregation & Hierarchy
        'BROKEN_AGGREGATION' => 'error',
        'MISSING_PARENT' => 'error',
        'MISSING_CHILDREN' => 'error',
        'AGGREGATION_QUANTITY_MISMATCH' => 'error',
        'MULTIPLE_PARENTS' => 'critical',
        'ORPHAN_SSCC' => 'error',
        'HIERARCHY_DEPTH_EXCEEDED' => 'warning',
        'PACKAGING_TYPE_CONFLICT' => 'warning',
        'DEAGGREGATION_WITHOUT_PRIOR' => 'error',

        // Quantity, Lot & Expiry
        'LOT_MISMATCH' => 'error',
        'QUANTITY_MISMATCH' => 'error',
        'MISSING_EXPIRY' => 'error',
        'EXPIRED_PRODUCT_SHIPPED' => 'critical',
        'MIXED_EXPIRY_SAME_LOT' => 'error',
        'PARTIAL_SHIPMENT_UNDECLARED' => 'warning',
        'OVER_SHIPMENT' => 'error',

        // Timing & Sequence
        'TIMING_INVERSION' => 'error',
        'COMMISSION_AFTER_SHIP' => 'critical',
        'EVENTS_OUT_OF_ORDER' => 'warning',
        'SHIP_BEFORE_COMMISSION' => 'critical',
        'DECOMMISSION_AFTER_SHIP' => 'error',

        // Transmission & Partner
        'PARTNER_REJECTED_FILE' => 'error',
        'MISSING_MDN' => 'warning',
        'LATE_MDN' => 'warning',
        'DUPLICATE_TRANSMISSION' => 'warning',
        'FILE_SIZE_EXCEEDED' => 'warning',
        'ENCODING_ERROR' => 'warning',
        'MISSING_SOURCE_DESTINATION' => 'error',
        'MISSING_BIZ_TRANSACTION' => 'warning',

        // Process & DSCSA Compliance
        'MISSING_COMMISSIONING' => 'critical',
        'SERIAL_SHIPPED_NOT_COMMISSIONED' => 'critical',
        'DECOMMISSIONED_SERIAL_SHIPPED' => 'critical',
        'SUSPECT_PRODUCT' => 'critical',
        'VERIFICATION_FAILED' => 'error',
        'RETURNS_NOT_LINKED' => 'error',
        'DROP_SHIPMENT_INDICATOR_MISSING' => 'warning',
        'OWNERSHIP_TRANSFER_UNCLEAR' => 'error',

        // System / Operational
        'L2_L3_RECONCILIATION_FAILURE' => 'error',
        'L3_TRANSMISSION_FAILURE' => 'error',
        'AUTO_DECOMMISSION_FAILED' => 'warning',
        'MASTER_DATA_SYNC_LAG' => 'warning',
        'INGESTION_PARSE_ERROR' => 'error',
        'INTERNAL_VALIDATION_FAILED' => 'error',

        // Validation cap overflow
        'FINDINGS_TRUNCATED' => 'warning',

        // Fallback
        'UNCLASSIFIED' => 'warning',
    ];

    /**
     * Explicit overrides layered on top of the default table (demo-safe posture
     * and hard critical-risk regulatory codes), independent of profile/hardness.
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        // Demo/interop-safe: cross-document history and ship-without-local-commission
        // are visible but must not block receiving until partner choreography is clean.
        'SHIP_BEFORE_COMMISSION' => 'warning',
        'SERIAL_SHIPPED_NOT_COMMISSIONED' => 'warning',
        'SERIAL_ALREADY_COMMISSIONED' => 'warning',
        'BROKEN_AGGREGATION' => 'warning',
        'MISSING_SOURCE_DESTINATION' => 'warning',
        'OWNERSHIP_TRANSFER_UNCLEAR' => 'warning',
        'ORPHAN_SSCC' => 'warning',
        'COMMISSION_AFTER_SHIP' => 'critical',
        'DECOMMISSIONED_SERIAL_SHIPPED' => 'critical',
        'FUTURE_EVENT_TIME' => 'error',
        'UNKNOWN_GLN' => 'warning',
        'UNKNOWN_GTIN' => 'warning',
        'MISSING_BIZ_TRANSACTION' => 'warning',
        'MASTER_DATA_SYNC_LAG' => 'warning',
        'INVALID_EXTENSION_NAMESPACE' => 'warning',
        'DUPLICATE_TRANSMISSION' => 'warning',
        'FILE_SIZE_EXCEEDED' => 'warning',
        'UNSUPPORTED_EPC_TYPE' => 'warning',
        'INVALID_COMPANY_PREFIX' => 'warning',
        'RETURNS_NOT_LINKED' => 'warning',
    ];

    /**
     * Codes only mandated under GS1 US R1.3: soft ('warning') unless the R1.3
     * profile is being hard-enforced for this document, in which case they
     * escalate to 'error'.
     *
     * @var list<string>
     */
    private const R13_ONLY_CODES = [
        'DROP_SHIPMENT_INDICATOR_MISSING',
    ];

    public static function severityFor(string $exceptionType, EpcisValidationContext $ctx): string
    {
        $code = strtoupper(trim($exceptionType));

        $configOverrides = config('tracepharma.epcis.validation.severity_overrides', []);
        if (is_array($configOverrides) && array_key_exists($code, $configOverrides)) {
            return (string) $configOverrides[$code];
        }

        if (in_array($code, self::R13_ONLY_CODES, true)) {
            return $ctx->r13Hard ? 'error' : 'warning';
        }

        if (array_key_exists($code, self::OVERRIDES)) {
            return self::OVERRIDES[$code];
        }

        return self::DEFAULT_SEVERITY[$code] ?? 'warning';
    }
}
