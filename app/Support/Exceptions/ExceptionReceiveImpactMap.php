<?php

namespace App\Support\Exceptions;

use App\Enums\ExceptionReceiveImpact;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionType;
use App\Support\Epcis\DetectDscsaGuidelineRelease;
use App\Support\Receiving\CmoOwnProductInbound;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical receive-impact assignment per exception type code.
 *
 * Used by {@see ExceptionTypeSeeder} and as a runtime fallback
 * when {@see ExceptionType::$receive_impact} is null.
 *
 * Document-aware soft overrides (CMO own-product TS / biz-transaction) live in
 * {@see self::forCodeOnDocument()} — supplier / email surfaces should prefer that
 * over {@see ExceptionType::blocksReceiving()} alone. Opt-in TI hard gate:
 * {@see config('tracepharma.epcis.hard_gate_missing_biz_transaction')}.
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
        'DAMAGED' => ExceptionReceiveImpact::HardBlocking,
        'PRODUCT_NO_DATA' => ExceptionReceiveImpact::HardBlocking,
        'AGGREGATION_BREAK' => ExceptionReceiveImpact::HardBlocking,
        'VERIFICATION_FAILED' => ExceptionReceiveImpact::HardBlocking,
        'INGESTION_PARSE_ERROR' => ExceptionReceiveImpact::HardBlocking,
        'UNKNOWN_GTIN' => ExceptionReceiveImpact::HardBlocking,
        'MISSING_PARENT' => ExceptionReceiveImpact::HardBlocking,
        'MISSING_CHILDREN' => ExceptionReceiveImpact::HardBlocking,
        'AGGREGATION_QUANTITY_MISMATCH' => ExceptionReceiveImpact::HardBlocking,
        'FUTURE_EVENT_TIME' => ExceptionReceiveImpact::HardBlocking,
        'PACK_HIERARCHY_TIME_INVERSION' => ExceptionReceiveImpact::HardBlocking,
        'DECOMMISSION_AFTER_SHIP' => ExceptionReceiveImpact::HardBlocking,
        'SERIAL_SHIPPED_NOT_COMMISSIONED' => ExceptionReceiveImpact::HardBlocking,
        'VOID_SHIPPING' => ExceptionReceiveImpact::HardBlocking,
        'FINDINGS_TRUNCATED' => ExceptionReceiveImpact::Warning,

        // Business rule / semantic — block until corrected
        'GTIN_SERIAL_MISMATCH' => ExceptionReceiveImpact::BusinessRule,
        'DELETE_WITHOUT_PRIOR_ADD' => ExceptionReceiveImpact::BusinessRule,
        'DEAGGREGATION_WITHOUT_PRIOR' => ExceptionReceiveImpact::BusinessRule,
        'LOT_MISMATCH' => ExceptionReceiveImpact::BusinessRule,
        'QUANTITY_MISMATCH' => ExceptionReceiveImpact::BusinessRule,
        'MISSING_EXPIRY' => ExceptionReceiveImpact::BusinessRule,
        'MIXED_EXPIRY_SAME_LOT' => ExceptionReceiveImpact::BusinessRule,
        'OVER_SHIPMENT' => ExceptionReceiveImpact::BusinessRule,
        'TIMING_INVERSION' => ExceptionReceiveImpact::BusinessRule,
        'PARTNER_REJECTED_FILE' => ExceptionReceiveImpact::BusinessRule,
        'L2_L3_RECONCILIATION_FAILURE' => ExceptionReceiveImpact::BusinessRule,
        'L3_TRANSMISSION_FAILURE' => ExceptionReceiveImpact::BusinessRule,
        'INTERNAL_VALIDATION_FAILED' => ExceptionReceiveImpact::BusinessRule,

        // Warning / quality — allow receive
        'LEADING_ZERO_STRIPPED' => ExceptionReceiveImpact::Warning,
        'UNSUPPORTED_EPC_TYPE' => ExceptionReceiveImpact::Warning,
        'INVALID_NDC_IDENTIFICATION_SHAPE' => ExceptionReceiveImpact::Warning,
        'INVALID_BIZSTEP' => ExceptionReceiveImpact::Warning,
        'INVALID_DISPOSITION' => ExceptionReceiveImpact::Warning,
        'STALE_EVENT' => ExceptionReceiveImpact::Warning,
        'INVALID_ACTION' => ExceptionReceiveImpact::Warning,
        'INVALID_EXTENSION_NAMESPACE' => ExceptionReceiveImpact::Warning,
        'MIXED_PACKAGING_LEVELS' => ExceptionReceiveImpact::Warning,
        'HIERARCHY_DEPTH_EXCEEDED' => ExceptionReceiveImpact::Warning,
        'PACKAGING_TYPE_CONFLICT' => ExceptionReceiveImpact::Warning,
        'PARTIAL_SHIPMENT_UNDECLARED' => ExceptionReceiveImpact::Warning,
        'SHORTAGE' => ExceptionReceiveImpact::Warning,
        'OVERAGE' => ExceptionReceiveImpact::Warning,
        'DATA_NO_PRODUCT' => ExceptionReceiveImpact::Warning,
        'EVENTS_OUT_OF_ORDER' => ExceptionReceiveImpact::Warning,
        'ENCODING_ERROR' => ExceptionReceiveImpact::Warning,
        'SBDH_SOURCE_OWNING_PARTY_MISMATCH' => ExceptionReceiveImpact::Warning,
        'DROP_SHIPMENT_INDICATOR_MISSING' => ExceptionReceiveImpact::Warning,
        'MIXED_DSCSA_GUIDELINE_RELEASE' => ExceptionReceiveImpact::Warning,
        'AUTO_DECOMMISSION_FAILED' => ExceptionReceiveImpact::Warning,
        'ASN_SHIPMENT_FILE_ADDED' => ExceptionReceiveImpact::Warning,
        'ASN_SHIPMENT_CORRECTED' => ExceptionReceiveImpact::Warning,
        'ASN_SHIPMENT_PO_MISMATCH' => ExceptionReceiveImpact::Warning,
        'DESTINATION_OWNING_PARTY_MISMATCH' => ExceptionReceiveImpact::Warning,
        'DESTINATION_LOCATION_MISMATCH' => ExceptionReceiveImpact::Warning,
        'SCHEDULED_PRODUCT_MISSING_DEA' => ExceptionReceiveImpact::Warning,
        'ERROR_DECLARATION' => ExceptionReceiveImpact::Warning,
        'INBOUND_RECEIVER_REJECTED' => ExceptionReceiveImpact::Warning,
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
        // Soft by default; {@see self::forCode()} may promote when hard_gate_missing_biz_transaction is on.
        'MISSING_BIZ_TRANSACTION' => ExceptionReceiveImpact::Soft,
        'MISSING_COMMISSIONING' => ExceptionReceiveImpact::HardBlocking,
        'RETURNS_NOT_LINKED' => ExceptionReceiveImpact::Soft,
        'OWNERSHIP_TRANSFER_UNCLEAR' => ExceptionReceiveImpact::Soft,
        'MASTER_DATA_SYNC_LAG' => ExceptionReceiveImpact::Soft,
        'CASE_ONLY_PALLET_COVERED' => ExceptionReceiveImpact::Soft,
    ];

    public static function forCode(?string $code): ExceptionReceiveImpact
    {
        if ($code === null || $code === '') {
            return ExceptionReceiveImpact::Warning;
        }

        $normalized = strtoupper(trim($code));

        if ($normalized === 'MISSING_BIZ_TRANSACTION') {
            return self::hardGateMissingBizTransactionEnabled()
                ? ExceptionReceiveImpact::HardBlocking
                : ExceptionReceiveImpact::Soft;
        }

        return self::MAP[$normalized] ?? ExceptionReceiveImpact::Warning;
    }

    /**
     * Document-aware impact: CMO own-product inbound softens TS / biz-transaction codes.
     * With hard_gate_missing_biz_transaction: both PO and ASN empty → HardBlocking;
     * ASN/DESADV present without PO → Soft.
     */
    /**
     * R1.3-only / serialized-ASN codes. R1.2 lot-only files must not hard-fail these.
     *
     * @var list<string>
     */
    private const R13_ONLY_OR_SERIALIZED_ASN_CODES = [
        'DROP_SHIPMENT_INDICATOR_MISSING',
        'MISSING_PARENT',
        'MISSING_CHILDREN',
        'AGGREGATION_QUANTITY_MISMATCH',
        'LOT_MISMATCH',
    ];

    public static function forCodeOnDocument(?string $code, ?EpcisDocument $document): ExceptionReceiveImpact
    {
        $normalized = strtoupper(trim((string) $code));

        if (
            $document !== null
            && CmoOwnProductInbound::applies($document)
            && in_array($normalized, ['MISSING_DSCSA_STATEMENT', 'MISSING_BIZ_TRANSACTION'], true)
        ) {
            return ExceptionReceiveImpact::Soft;
        }

        if ($normalized === 'MISSING_BIZ_TRANSACTION') {
            if (! self::hardGateMissingBizTransactionEnabled()) {
                return ExceptionReceiveImpact::Soft;
            }

            // DESADV/ASN (or PO) present → Soft; only both-empty ownership-change TI is HardBlocking.
            if ($document !== null && (filled($document->asn_number) || filled($document->customer_po))) {
                return ExceptionReceiveImpact::Soft;
            }

            return ExceptionReceiveImpact::HardBlocking;
        }

        $r12LotOnly = DetectDscsaGuidelineRelease::documentIsR12LotOnly($document);

        if ($normalized === 'SERIAL_SHIPPED_NOT_COMMISSIONED') {
            if ($r12LotOnly) {
                return ExceptionReceiveImpact::Soft;
            }

            if ($document !== null && self::tenantHasCommissioningForDocument($document)) {
                return ExceptionReceiveImpact::Warning;
            }

            return ExceptionReceiveImpact::HardBlocking;
        }

        if ($r12LotOnly && in_array($normalized, self::R13_ONLY_OR_SERIALIZED_ASN_CODES, true)) {
            return ExceptionReceiveImpact::Warning;
        }

        return self::forCode($code);
    }

    private static function tenantHasCommissioningForDocument(EpcisDocument $document): bool
    {
        if (! Schema::hasTable('event_epcs') || ! Schema::hasTable('epcis_events')) {
            return false;
        }

        $epcIds = [];
        if (Schema::hasTable('document_epcs') && $document->getKey() !== null) {
            $epcIds = DB::table('document_epcs')
                ->where('document_id', $document->getKey())
                ->pluck('epc_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        if ($epcIds === []) {
            return false;
        }

        return DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->whereIn('event_epcs.epc_id', $epcIds)
            ->where('event_epcs.role', 'epcList')
            ->where('epcis_events.event_type', 'ObjectEvent')
            ->where('epcis_events.action', 'ADD')
            ->whereRaw("LOWER(epcis_events.biz_step) LIKE '%commissioning%'")
            ->exists();
    }

    private static function hardGateMissingBizTransactionEnabled(): bool
    {
        return (bool) config('tracepharma.epcis.hard_gate_missing_biz_transaction', false);
    }

    /**
     * @return array<string, ExceptionReceiveImpact>
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
