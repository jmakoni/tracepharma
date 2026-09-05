<?php

namespace App\Support\Exceptions;

use App\Enums\ExceptionReceiveImpact;
use App\Models\Exceptions\ExceptionType;
use Database\Seeders\ExceptionTypeSeeder;

/**
 * Canonical receive-impact assignment per exception type code.
 *
 * Used by {@see ExceptionTypeSeeder} and as a runtime fallback
 * when {@see ExceptionType::$receive_impact} is null.
 */
final class ExceptionReceiveImpactMap
{
    /**
     * @var array<string, ExceptionReceiveImpact>
     */
    private const MAP = [
        // Hard / blocking — integrity & DSCSA stop-ship
        'DUPLICATE_SERIAL' => ExceptionReceiveImpact::HardBlocking,
        'INVALID_GTIN_CHECK_DIGIT' => ExceptionReceiveImpact::HardBlocking,
        'INVALID_SSCC_CHECK_DIGIT' => ExceptionReceiveImpact::HardBlocking,
        'INVALID_EPC_URI' => ExceptionReceiveImpact::HardBlocking,
        'MISSING_MANDATORY_FIELD' => ExceptionReceiveImpact::HardBlocking,
        'MISSING_DSCSA_STATEMENT' => ExceptionReceiveImpact::HardBlocking,
        'MULTIPLE_PARENTS' => ExceptionReceiveImpact::HardBlocking,
        'EXPIRED_PRODUCT_SHIPPED' => ExceptionReceiveImpact::HardBlocking,
        'COMMISSION_AFTER_SHIP' => ExceptionReceiveImpact::HardBlocking,
        'DECOMMISSIONED_SERIAL_SHIPPED' => ExceptionReceiveImpact::HardBlocking,
        'SUSPECT_PRODUCT' => ExceptionReceiveImpact::HardBlocking,
        'VERIFICATION_FAILED' => ExceptionReceiveImpact::HardBlocking,
        'INGESTION_PARSE_ERROR' => ExceptionReceiveImpact::HardBlocking,
        'FINDINGS_TRUNCATED' => ExceptionReceiveImpact::Warning,

        // Business rule / semantic — block until corrected
        'UNKNOWN_GTIN' => ExceptionReceiveImpact::BusinessRule,
        'GTIN_SERIAL_MISMATCH' => ExceptionReceiveImpact::BusinessRule,
        'FUTURE_EVENT_TIME' => ExceptionReceiveImpact::BusinessRule,
        'DELETE_WITHOUT_PRIOR_ADD' => ExceptionReceiveImpact::BusinessRule,
        'MISSING_PARENT' => ExceptionReceiveImpact::BusinessRule,
        'MISSING_CHILDREN' => ExceptionReceiveImpact::BusinessRule,
        'AGGREGATION_QUANTITY_MISMATCH' => ExceptionReceiveImpact::BusinessRule,
        'DEAGGREGATION_WITHOUT_PRIOR' => ExceptionReceiveImpact::BusinessRule,
        'LOT_MISMATCH' => ExceptionReceiveImpact::BusinessRule,
        'QUANTITY_MISMATCH' => ExceptionReceiveImpact::BusinessRule,
        'MISSING_EXPIRY' => ExceptionReceiveImpact::BusinessRule,
        'MIXED_EXPIRY_SAME_LOT' => ExceptionReceiveImpact::BusinessRule,
        'OVER_SHIPMENT' => ExceptionReceiveImpact::BusinessRule,
        'TIMING_INVERSION' => ExceptionReceiveImpact::BusinessRule,
        'PACK_HIERARCHY_TIME_INVERSION' => ExceptionReceiveImpact::BusinessRule,
        'DECOMMISSION_AFTER_SHIP' => ExceptionReceiveImpact::BusinessRule,
        'PARTNER_REJECTED_FILE' => ExceptionReceiveImpact::BusinessRule,
        'L2_L3_RECONCILIATION_FAILURE' => ExceptionReceiveImpact::BusinessRule,
        'L3_TRANSMISSION_FAILURE' => ExceptionReceiveImpact::BusinessRule,
        'INTERNAL_VALIDATION_FAILED' => ExceptionReceiveImpact::BusinessRule,

        // Warning / quality — allow receive
        'LEADING_ZERO_STRIPPED' => ExceptionReceiveImpact::Warning,
        'UNSUPPORTED_EPC_TYPE' => ExceptionReceiveImpact::Warning,
        'INVALID_BIZSTEP' => ExceptionReceiveImpact::Warning,
        'INVALID_DISPOSITION' => ExceptionReceiveImpact::Warning,
        'STALE_EVENT' => ExceptionReceiveImpact::Warning,
        'INVALID_ACTION' => ExceptionReceiveImpact::Warning,
        'INVALID_EXTENSION_NAMESPACE' => ExceptionReceiveImpact::Warning,
        'MIXED_PACKAGING_LEVELS' => ExceptionReceiveImpact::Warning,
        'HIERARCHY_DEPTH_EXCEEDED' => ExceptionReceiveImpact::Warning,
        'PACKAGING_TYPE_CONFLICT' => ExceptionReceiveImpact::Warning,
        'PARTIAL_SHIPMENT_UNDECLARED' => ExceptionReceiveImpact::Warning,
        'EVENTS_OUT_OF_ORDER' => ExceptionReceiveImpact::Warning,
        'ENCODING_ERROR' => ExceptionReceiveImpact::Warning,
        'SBDH_SOURCE_OWNING_PARTY_MISMATCH' => ExceptionReceiveImpact::Warning,
        'DROP_SHIPMENT_INDICATOR_MISSING' => ExceptionReceiveImpact::Warning,
        'AUTO_DECOMMISSION_FAILED' => ExceptionReceiveImpact::Warning,
        'ASN_SHIPMENT_FILE_ADDED' => ExceptionReceiveImpact::Warning,
        'ASN_SHIPMENT_PO_MISMATCH' => ExceptionReceiveImpact::Warning,
        'DESTINATION_OWNING_PARTY_MISMATCH' => ExceptionReceiveImpact::Warning,
        'DESTINATION_LOCATION_MISMATCH' => ExceptionReceiveImpact::Warning,
        'SCHEDULED_PRODUCT_MISSING_DEA' => ExceptionReceiveImpact::Warning,
        'UNCLASSIFIED' => ExceptionReceiveImpact::Warning,

        // Soft / informational — demo-softened / never gate receive
        'SERIAL_ALREADY_COMMISSIONED' => ExceptionReceiveImpact::Soft,
        'UNKNOWN_GLN' => ExceptionReceiveImpact::Soft,
        'INVALID_COMPANY_PREFIX' => ExceptionReceiveImpact::Soft,
        'BROKEN_AGGREGATION' => ExceptionReceiveImpact::Soft,
        'ORPHAN_SSCC' => ExceptionReceiveImpact::Soft,
        'SHIP_BEFORE_COMMISSION' => ExceptionReceiveImpact::Soft,
        'MISSING_MDN' => ExceptionReceiveImpact::Soft,
        'LATE_MDN' => ExceptionReceiveImpact::Soft,
        'DUPLICATE_TRANSMISSION' => ExceptionReceiveImpact::Soft,
        'FILE_SIZE_EXCEEDED' => ExceptionReceiveImpact::Soft,
        'MISSING_SOURCE_DESTINATION' => ExceptionReceiveImpact::Soft,
        'MISSING_BIZ_TRANSACTION' => ExceptionReceiveImpact::Soft,
        'MISSING_COMMISSIONING' => ExceptionReceiveImpact::HardBlocking,
        'SERIAL_SHIPPED_NOT_COMMISSIONED' => ExceptionReceiveImpact::Soft,
        'RETURNS_NOT_LINKED' => ExceptionReceiveImpact::Soft,
        'OWNERSHIP_TRANSFER_UNCLEAR' => ExceptionReceiveImpact::Soft,
        'MASTER_DATA_SYNC_LAG' => ExceptionReceiveImpact::Soft,
    ];

    public static function forCode(?string $code): ExceptionReceiveImpact
    {
        if ($code === null || $code === '') {
            return ExceptionReceiveImpact::Warning;
        }

        $normalized = strtoupper(trim($code));

        return self::MAP[$normalized] ?? ExceptionReceiveImpact::Warning;
    }

    /**
     * @return array<string, ExceptionReceiveImpact>
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
