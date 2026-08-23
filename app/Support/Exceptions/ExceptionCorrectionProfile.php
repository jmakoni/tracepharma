<?php

namespace App\Support\Exceptions;

use App\Actions\Epcis\ProcessEpcisDocument;
use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Support\Epcis\Validation\EpcisCatalogBusinessRules;
use Database\Seeders\ExceptionTypeSeeder;

/**
 * Maps an {@see ExceptionType} code (exception_types.code) to a
 * "correction family" plus the UI/UX hints an exception detail screen needs to guide a
 * user toward the right fix: which primary action to surface, which root_cause /
 * resolution_action codes to pre-select, helper copy explaining the issue, and whether to
 * emphasize document tools, a master-data quick-add form, or quarantine handling.
 *
 * Source of truth for codes is {@see ExceptionTypeSeeder} and the codes
 * actually emitted by {@see EpcisCatalogBusinessRules},
 * {@see ValidateEpcis12Document} and {@see ProcessEpcisDocument}.
 *
 * Codes that exist in the catalog only as unwired stubs — the partner/MDN/VRS/L2-L3 hooks in
 * {@see RecordOperationalEpcisCatalogSignal} that no job/controller calls yet
 * (PARTNER_REJECTED_FILE, MISSING_MDN, LATE_MDN, L2_L3_RECONCILIATION_FAILURE,
 * L3_TRANSMISSION_FAILURE, AUTO_DECOMMISSION_FAILED) — plus the orphaned TIMING_INVERSION and
 * SHIP_BEFORE_COMMISSION (declared in the catalog/severity map but never raised by a validator;
 * superseded for live detection by {@see EpcisCatalogBusinessRules} emitting
 * SERIAL_SHIPPED_NOT_COMMISSIONED and MISSING_COMMISSIONING instead — keep stub-hidden, no new emitter)
 * intentionally fall through to the generic {@see self::FAMILY_FALLBACK} profile.
 */
final class ExceptionCorrectionProfile
{
    // --- Correction families -------------------------------------------------------------

    public const FAMILY_MASTER_DATA_PRODUCT = 'master_data_product';

    public const FAMILY_MASTER_DATA_LOCATION = 'master_data_location';

    public const FAMILY_DOCUMENT = 'document';

    public const FAMILY_QUARANTINE = 'quarantine';

    public const FAMILY_AGGREGATION = 'aggregation';

    public const FAMILY_TIMING = 'timing';

    public const FAMILY_WAIVE = 'waive';

    public const FAMILY_FALLBACK = 'fallback';

    // --- Primary action keys --------------------------------------------------------------

    public const ACTION_ADD_PRODUCT = 'add_product';

    public const ACTION_REGISTER_GLN = 'register_gln';

    public const ACTION_FIX_DOCUMENT = 'fix_document';

    public const ACTION_QUARANTINE = 'quarantine';

    public const ACTION_INVESTIGATE_PARTNER = 'investigate_partner';

    public const ACTION_WAIVE = 'waive';

    public const ACTION_GENERIC_RESOLVE = 'generic_resolve';

    private function __construct(
        private readonly string $code,
        private readonly string $family,
        private readonly string $primaryActionKey,
        private readonly string $primaryActionLabel,
        private readonly string $suggestedCorrectionBlurb,
        private readonly ?string $suggestedRootCauseCode,
        private readonly ?string $suggestedResolutionActionCode,
        private readonly bool $emphasizeQuarantine,
        private readonly bool $showsDocumentTools,
        private readonly bool $showsMasterDataProductForm,
        private readonly bool $showsMasterDataLocationForm,
        private readonly bool $showsWaive,
    ) {}

    /**
     * Build the correction profile for an exception_types.code value. Null/blank/unknown
     * codes resolve to the generic fallback profile ({@see self::isSpecialized()} === false).
     */
    public static function for(?string $code): self
    {
        $normalized = strtoupper(trim((string) $code));

        $family = self::CODE_FAMILY[$normalized] ?? self::FAMILY_FALLBACK;
        $defaults = self::FAMILY_DEFAULTS[$family];
        $overrides = self::CODE_OVERRIDES[$normalized] ?? [];
        $merged = [...$defaults, ...$overrides];

        return new self(
            code: $normalized,
            family: $family,
            primaryActionKey: $merged['primaryActionKey'],
            primaryActionLabel: $merged['primaryActionLabel'],
            suggestedCorrectionBlurb: $merged['blurb'],
            suggestedRootCauseCode: $merged['rootCause'],
            suggestedResolutionActionCode: $merged['resolutionAction'],
            emphasizeQuarantine: $merged['quarantine'],
            showsDocumentTools: $merged['docTools'],
            showsMasterDataProductForm: $merged['productForm'],
            showsMasterDataLocationForm: $merged['locationForm'],
            showsWaive: $merged['waive'],
        );
    }

    /**
     * Prefer the stored type code, then the linked ingest signal, then GTIN-in-copy.
     * Tenants that only have UNCLASSIFIED still get the product-authorize actions
     * for unknown-GTIN ingest.
     */
    public static function forCase(ExceptionCase $case): self
    {
        $fromType = self::for($case->type?->code);

        if ($fromType->showsMasterDataProductForm() || $fromType->isSpecialized()) {
            return $fromType;
        }

        $signalType = $case->relationLoaded('signals')
            ? $case->signals->pluck('exception_type')->filter()->first()
            : $case->signals()->value('exception_type');

        if (is_string($signalType) && $signalType !== '') {
            $fromSignal = self::for($signalType);

            if ($fromSignal->showsMasterDataProductForm() || $fromSignal->isSpecialized()) {
                return $fromSignal;
            }
        }

        if (
            self::extractGtinFromDescription($case->description) !== null
            && str_contains(strtolower((string) $case->description), 'gtin not found')
        ) {
            return self::for('UNKNOWN_GTIN');
        }

        return $fromType;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function family(): string
    {
        return $this->family;
    }

    public function primaryActionKey(): string
    {
        return $this->primaryActionKey;
    }

    public function primaryActionLabel(): string
    {
        return $this->primaryActionLabel;
    }

    public function suggestedCorrectionBlurb(): string
    {
        return $this->suggestedCorrectionBlurb;
    }

    public function suggestedRootCauseCode(): ?string
    {
        return $this->suggestedRootCauseCode;
    }

    public function suggestedResolutionActionCode(): ?string
    {
        return $this->suggestedResolutionActionCode;
    }

    public function emphasizeQuarantine(): bool
    {
        return $this->emphasizeQuarantine;
    }

    public function showsDocumentTools(): bool
    {
        return $this->showsDocumentTools;
    }

    public function showsMasterDataProductForm(): bool
    {
        return $this->showsMasterDataProductForm;
    }

    public function showsMasterDataLocationForm(): bool
    {
        return $this->showsMasterDataLocationForm;
    }

    public function showsWaive(): bool
    {
        return $this->showsWaive;
    }

    /**
     * Case-aware waive gate: profile may allow waiver, but receiving_issues-sourced
     * PARTIAL_SHIPMENT_UNDECLARED must not be waived (force investigate / non-waiver resolve).
     */
    public static function showsWaiveForCase(ExceptionCase $case): bool
    {
        $profile = self::forCase($case);

        if (! $profile->showsWaive()) {
            return false;
        }

        $code = strtoupper(trim((string) $case->type?->code));

        if ($code !== 'PARTIAL_SHIPMENT_UNDECLARED') {
            return true;
        }

        return ! $case->activities()
            ->where('meta->source', 'receiving_issues')
            ->exists();
    }

    /**
     * True whenever this code resolved to a real family instead of the generic fallback —
     * i.e. the UI has a specific, non-generic correction workflow to offer.
     */
    public function isSpecialized(): bool
    {
        return $this->family !== self::FAMILY_FALLBACK;
    }

    /**
     * Unwired stub / orphan exception_types.code values that must not appear in operator
     * type filters or create pickers. Existing exception records of these types still
     * display when present — this list only hides them from selection UIs.
     *
     * Matches the stub/orphan annotations on {@see self::CODE_FAMILY}. UNCLASSIFIED is
     * intentionally kept (fallback in real use), even though it also maps to FAMILY_FALLBACK.
     *
     * @return list<string>
     */
    public static function operatorHiddenStubCodes(): array
    {
        return [
            'PARTNER_REJECTED_FILE',
            'MISSING_MDN',
            'LATE_MDN',
            'L2_L3_RECONCILIATION_FAILURE',
            'L3_TRANSMISSION_FAILURE',
            'AUTO_DECOMMISSION_FAILED',
            'TIMING_INVERSION',
            'SHIP_BEFORE_COMMISSION',
        ];
    }

    public static function isOperatorHiddenStubCode(?string $code): bool
    {
        $normalized = strtoupper(trim((string) $code));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, self::operatorHiddenStubCodes(), true);
    }

    /**
     * Extract a GTIN-8/12/13/14 embedded in exception copy such as
     * "GTIN not found in product master: 30301164005087".
     */
    public static function extractGtinFromDescription(?string $description): ?string
    {
        $gtins = self::extractGtinsFromDescription($description);

        return $gtins[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function extractGtinsFromDescription(?string $description): array
    {
        if ($description === null || $description === '') {
            return [];
        }

        if (preg_match_all('/\bGTIN[A-Za-z0-9\-]*\b[^0-9]*(\d{8}|\d{12,14})\b/i', $description, $matches) < 1) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Extract a GLN-13 embedded in exception copy such as
     * "Unmatched GLN referenced in document: 0812345000010".
     */
    public static function extractGlnFromDescription(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }

        if (preg_match('/\bGLN\b[^0-9]*(\d{13})\b/i', $description, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Per-family default copy/action/flags. Individual codes override a subset of these via
     * {@see self::CODE_OVERRIDES}.
     *
     * @var array<string, array{
     *     primaryActionKey: string,
     *     primaryActionLabel: string,
     *     blurb: string,
     *     rootCause: ?string,
     *     resolutionAction: ?string,
     *     quarantine: bool,
     *     docTools: bool,
     *     productForm: bool,
     *     locationForm: bool,
     *     waive: bool,
     * }>
     */
    private const FAMILY_DEFAULTS = [
        self::FAMILY_MASTER_DATA_PRODUCT => [
            'primaryActionKey' => self::ACTION_ADD_PRODUCT,
            'primaryActionLabel' => 'Add product to assortment',
            'blurb' => 'This GTIN is not in your product master. Add it to the product assortment so future EPCIS events for this item stop raising this exception.',
            'rootCause' => 'internal_mapping_error',
            'resolutionAction' => 'update_master_data',
            'quarantine' => false,
            'docTools' => false,
            'productForm' => true,
            'locationForm' => false,
            'waive' => false,
        ],
        self::FAMILY_MASTER_DATA_LOCATION => [
            'primaryActionKey' => self::ACTION_REGISTER_GLN,
            'primaryActionLabel' => 'Register GLN',
            'blurb' => 'This GLN is not recognized as one of your locations or a known trading partner location. Register it so future events resolve automatically.',
            'rootCause' => 'internal_mapping_error',
            'resolutionAction' => 'update_master_data',
            'quarantine' => false,
            'docTools' => false,
            'productForm' => false,
            'locationForm' => true,
            'waive' => false,
        ],
        self::FAMILY_DOCUMENT => [
            'primaryActionKey' => self::ACTION_FIX_DOCUMENT,
            'primaryActionLabel' => 'Fix or replace document',
            'blurb' => 'This exception was raised by structural validation of the ingested EPCIS document. Review the document contents, correct the offending field, and reprocess.',
            'rootCause' => 'file_format_issue',
            'resolutionAction' => 'reprocess_document',
            'quarantine' => false,
            'docTools' => true,
            'productForm' => false,
            'locationForm' => false,
            'waive' => false,
        ],
        self::FAMILY_QUARANTINE => [
            'primaryActionKey' => self::ACTION_QUARANTINE,
            'primaryActionLabel' => 'Quarantine & investigate',
            'blurb' => 'This is a DSCSA product-integrity signal. Hold the affected units, open an investigation, and do not release to distribution until disposition is confirmed.',
            'rootCause' => 'unknown',
            'resolutionAction' => 'quarantine_product',
            'quarantine' => true,
            'docTools' => false,
            'productForm' => false,
            'locationForm' => false,
            'waive' => false,
        ],
        self::FAMILY_AGGREGATION => [
            'primaryActionKey' => self::ACTION_INVESTIGATE_PARTNER,
            'primaryActionLabel' => 'Investigate & request partner correction',
            'blurb' => 'The packaging/aggregation hierarchy declared in the EPCIS event does not reconcile. Investigate the parent/child relationship and, if the data originated upstream, request a correction from the trading partner.',
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
            'quarantine' => false,
            'docTools' => false,
            'productForm' => false,
            'locationForm' => false,
            'waive' => false,
        ],
        self::FAMILY_TIMING => [
            'primaryActionKey' => self::ACTION_INVESTIGATE_PARTNER,
            'primaryActionLabel' => 'Investigate & request partner correction',
            'blurb' => 'Events for this EPC arrived out of the expected chronological order. Confirm the correct sequence with the sending system/partner, or accept with a waiver if this is a known benign reordering (e.g. batch upload timing).',
            'rootCause' => 'process_timing',
            'resolutionAction' => 'request_partner_correction',
            'quarantine' => false,
            'docTools' => false,
            'productForm' => false,
            'locationForm' => false,
            'waive' => true,
        ],
        self::FAMILY_WAIVE => [
            'primaryActionKey' => self::ACTION_WAIVE,
            'primaryActionLabel' => 'Accept with waiver',
            'blurb' => 'This signal is informational or a likely false positive. Document the reasoning and accept it with a waiver rather than pursuing a data correction.',
            'rootCause' => 'unknown',
            'resolutionAction' => 'accept_with_waiver',
            'quarantine' => false,
            'docTools' => false,
            'productForm' => false,
            'locationForm' => false,
            'waive' => true,
        ],
        self::FAMILY_FALLBACK => [
            'primaryActionKey' => self::ACTION_GENERIC_RESOLVE,
            'primaryActionLabel' => 'Resolve',
            'blurb' => 'Review the exception details and choose the root cause and resolution that best match what happened.',
            'rootCause' => null,
            'resolutionAction' => null,
            'quarantine' => false,
            'docTools' => false,
            'productForm' => false,
            'locationForm' => false,
            'waive' => true,
        ],
    ];

    /**
     * exception_types.code => correction family. Mirrors the category groupings in
     * {@see ExceptionTypeSeeder} for traceability; inline comments call out
     * codes that don't fire yet.
     *
     * @var array<string, string>
     */
    private const CODE_FAMILY = [
        // Identifier & Master Data
        'DUPLICATE_SERIAL' => self::FAMILY_DOCUMENT,
        'SERIAL_ALREADY_COMMISSIONED' => self::FAMILY_DOCUMENT,
        'UNKNOWN_GTIN' => self::FAMILY_MASTER_DATA_PRODUCT,
        'INVALID_GTIN_CHECK_DIGIT' => self::FAMILY_DOCUMENT,
        'INVALID_SSCC_CHECK_DIGIT' => self::FAMILY_DOCUMENT,
        'UNKNOWN_GLN' => self::FAMILY_MASTER_DATA_LOCATION,
        'INVALID_COMPANY_PREFIX' => self::FAMILY_DOCUMENT,
        'LEADING_ZERO_STRIPPED' => self::FAMILY_DOCUMENT, // hook-only today (RecordOperationalEpcisCatalogSignal); mapped for when it fires
        'GTIN_SERIAL_MISMATCH' => self::FAMILY_DOCUMENT, // hook-only today
        'INVALID_EPC_URI' => self::FAMILY_DOCUMENT,
        'UNSUPPORTED_EPC_TYPE' => self::FAMILY_DOCUMENT,

        // Event Structure & Content
        'MISSING_MANDATORY_FIELD' => self::FAMILY_DOCUMENT,
        'INVALID_BIZSTEP' => self::FAMILY_DOCUMENT,
        'INVALID_DISPOSITION' => self::FAMILY_DOCUMENT,
        'FUTURE_EVENT_TIME' => self::FAMILY_DOCUMENT,
        'STALE_EVENT' => self::FAMILY_DOCUMENT,
        'INVALID_ACTION' => self::FAMILY_DOCUMENT,
        'DELETE_WITHOUT_PRIOR_ADD' => self::FAMILY_DOCUMENT, // not currently emitted; mapped by structural category
        'MISSING_DSCSA_STATEMENT' => self::FAMILY_DOCUMENT,
        'INVALID_EXTENSION_NAMESPACE' => self::FAMILY_DOCUMENT, // hook-only today
        'MIXED_PACKAGING_LEVELS' => self::FAMILY_AGGREGATION,

        // Aggregation & Hierarchy
        'BROKEN_AGGREGATION' => self::FAMILY_AGGREGATION,
        'MISSING_PARENT' => self::FAMILY_AGGREGATION,
        'MISSING_CHILDREN' => self::FAMILY_AGGREGATION,
        'AGGREGATION_QUANTITY_MISMATCH' => self::FAMILY_AGGREGATION,
        'MULTIPLE_PARENTS' => self::FAMILY_AGGREGATION,
        'ORPHAN_SSCC' => self::FAMILY_AGGREGATION,
        'HIERARCHY_DEPTH_EXCEEDED' => self::FAMILY_AGGREGATION,
        'PACKAGING_TYPE_CONFLICT' => self::FAMILY_AGGREGATION, // hook-only today
        'DEAGGREGATION_WITHOUT_PRIOR' => self::FAMILY_AGGREGATION,

        // Quantity, Lot & Expiry
        'LOT_MISMATCH' => self::FAMILY_DOCUMENT, // hook-only today
        'QUANTITY_MISMATCH' => self::FAMILY_DOCUMENT, // hook-only today
        'MISSING_EXPIRY' => self::FAMILY_DOCUMENT,
        'EXPIRED_PRODUCT_SHIPPED' => self::FAMILY_QUARANTINE,
        'MIXED_EXPIRY_SAME_LOT' => self::FAMILY_DOCUMENT,
        'PARTIAL_SHIPMENT_UNDECLARED' => self::FAMILY_DOCUMENT, // hook-only today
        'OVER_SHIPMENT' => self::FAMILY_DOCUMENT, // hook-only today

        // Timing & Sequence
        'TIMING_INVERSION' => self::FAMILY_FALLBACK, // orphaned: catalogued/severity-mapped but never raised
        'COMMISSION_AFTER_SHIP' => self::FAMILY_TIMING,
        'EVENTS_OUT_OF_ORDER' => self::FAMILY_TIMING,
        'SHIP_BEFORE_COMMISSION' => self::FAMILY_FALLBACK, // orphaned/superseded by SERIAL_SHIPPED_NOT_COMMISSIONED + MISSING_COMMISSIONING
        'DECOMMISSION_AFTER_SHIP' => self::FAMILY_TIMING,

        // Transmission & Partner
        'PARTNER_REJECTED_FILE' => self::FAMILY_FALLBACK, // stub: RecordOperationalEpcisCatalogSignal hook, no caller yet
        'MISSING_MDN' => self::FAMILY_FALLBACK, // stub: MDN hook, no caller yet
        'LATE_MDN' => self::FAMILY_FALLBACK, // stub: MDN hook, no caller yet
        'DUPLICATE_TRANSMISSION' => self::FAMILY_DOCUMENT,
        'FILE_SIZE_EXCEEDED' => self::FAMILY_DOCUMENT,
        'ENCODING_ERROR' => self::FAMILY_DOCUMENT, // hook-only today
        'MISSING_SOURCE_DESTINATION' => self::FAMILY_DOCUMENT,
        'MISSING_BIZ_TRANSACTION' => self::FAMILY_DOCUMENT,

        // Process & DSCSA Compliance
        'MISSING_COMMISSIONING' => self::FAMILY_QUARANTINE,
        'SERIAL_SHIPPED_NOT_COMMISSIONED' => self::FAMILY_QUARANTINE,
        'DECOMMISSIONED_SERIAL_SHIPPED' => self::FAMILY_QUARANTINE,
        'SUSPECT_PRODUCT' => self::FAMILY_QUARANTINE,
        'VERIFICATION_FAILED' => self::FAMILY_QUARANTINE, // VRS hook exists but unwired; mapped for when it fires
        'RETURNS_NOT_LINKED' => self::FAMILY_DOCUMENT,
        'DROP_SHIPMENT_INDICATOR_MISSING' => self::FAMILY_DOCUMENT,
        'OWNERSHIP_TRANSFER_UNCLEAR' => self::FAMILY_DOCUMENT,

        // System / Operational
        'L2_L3_RECONCILIATION_FAILURE' => self::FAMILY_FALLBACK, // stub: L2/L3 hook, no caller yet
        'L3_TRANSMISSION_FAILURE' => self::FAMILY_FALLBACK, // stub: L2/L3 hook, no caller yet
        'AUTO_DECOMMISSION_FAILED' => self::FAMILY_FALLBACK, // stub: hook, no caller yet
        'MASTER_DATA_SYNC_LAG' => self::FAMILY_MASTER_DATA_PRODUCT,
        'INGESTION_PARSE_ERROR' => self::FAMILY_DOCUMENT,
        'INTERNAL_VALIDATION_FAILED' => self::FAMILY_DOCUMENT,
        'FINDINGS_TRUNCATED' => self::FAMILY_DOCUMENT,

        // Fallback
        'UNCLASSIFIED' => self::FAMILY_FALLBACK,
    ];

    /**
     * Sparse per-code overrides layered on top of {@see self::FAMILY_DEFAULTS} for codes that
     * need distinctive copy, a different suggested root cause/resolution, or non-default flags.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CODE_OVERRIDES = [
        'UNKNOWN_GTIN' => [
            'blurb' => 'GTIN not found in product master. Add this GTIN to the product assortment so future shipments for this item resolve automatically instead of raising this exception. When several GTINs are missing on one document, use the document Products tab to authorize catalog hits in bulk.',
        ],
        'UNKNOWN_GLN' => [
            'blurb' => 'GLN not recognized by the system or the trading partner. Register this GLN as one of your locations or a known partner location.',
        ],
        'MASTER_DATA_SYNC_LAG' => [
            'primaryActionLabel' => 'Update product or location master data',
            'blurb' => 'Product or location master data is out of date (placeholder values such as "N/A" are present for a GTIN/GLN referenced in this document). Refresh the record — it may be a product or a location, so both quick-add forms are available.',
            'locationForm' => true,
            'waive' => true,
        ],

        // Quarantine / DSCSA risk
        'SUSPECT_PRODUCT' => [
            'blurb' => 'Potential illegitimate product indicator (DSCSA suspect/illegitimate product). Quarantine the affected units immediately, open holds, and if disposition confirms illegitimacy, notify FDA and trading partners within 24 hours per Form FDA 3911.',
        ],
        'EXPIRED_PRODUCT_SHIPPED' => [
            'blurb' => 'Product was shipped after its expiry date — it is not eligible for distribution. Quarantine the affected units and confirm they are pulled from any in-transit or receivable inventory.',
        ],
        'DECOMMISSIONED_SERIAL_SHIPPED' => [
            'blurb' => 'A serial that was already decommissioned was subsequently shipped. This is a critical chain-of-custody break — quarantine the units and investigate how a decommissioned serial re-entered the outbound flow.',
        ],
        'VERIFICATION_FAILED' => [
            'blurb' => 'A trading partner verification (VRS) request failed for this product. Quarantine the affected units pending a successful re-verification or confirmed disposition.',
        ],
        'MISSING_COMMISSIONING' => [
            'blurb' => 'An EPC was packed or shipped in this document while still reserved (no usable commissioning in this document) — high DSCSA regulatory risk. Quarantine the affected units and investigate where commissioning should have occurred.',
        ],
        'SERIAL_SHIPPED_NOT_COMMISSIONED' => [
            'blurb' => 'A serial was shipped but was never commissioned — high DSCSA regulatory risk. Quarantine the affected units and investigate the commissioning gap before releasing to distribution.',
        ],

        // Timing & sequence — commission/decommission-after-ship carry the same regulatory
        // risk as "shipped not commissioned" / "decommissioned serial shipped", so quarantine
        // is emphasized in addition to the timing family's default partner-investigation action.
        'COMMISSION_AFTER_SHIP' => [
            'blurb' => 'This serial was commissioned after it was already shipped — the same regulatory risk profile as a serial shipped without commissioning. Quarantine the affected units while you investigate the sequencing with the sending system/partner.',
            'quarantine' => true,
            'waive' => false,
        ],
        'DECOMMISSION_AFTER_SHIP' => [
            'blurb' => 'This serial was decommissioned after it had already left the facility. Quarantine the affected units while you investigate why decommissioning happened out of sequence.',
            'quarantine' => true,
            'waive' => false,
        ],
        'EVENTS_OUT_OF_ORDER' => [
            'blurb' => 'Events for this EPC were received out of chronological order. This is often benign (e.g. batch upload timing) — investigate with the sending system, or accept with a waiver if the sequence is otherwise explainable.',
        ],

        // Aggregation / hierarchy — hook-only or naturally lower-risk codes get a waive escape hatch.
        'HIERARCHY_DEPTH_EXCEEDED' => ['waive' => true],
        'PACKAGING_TYPE_CONFLICT' => ['waive' => true],
        'MIXED_PACKAGING_LEVELS' => ['waive' => true],

        // Document correction — partner-originated data reconciliation issues: suggest
        // requesting a partner correction instead of a straight reprocess.
        'MISSING_SOURCE_DESTINATION' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'MISSING_BIZ_TRANSACTION' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
            'waive' => true,
        ],
        'OWNERSHIP_TRANSFER_UNCLEAR' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'RETURNS_NOT_LINKED' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'DROP_SHIPMENT_INDICATOR_MISSING' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
            'waive' => true,
        ],
        'DUPLICATE_TRANSMISSION' => [
            'rootCause' => 'duplicate_transmission',
            'resolutionAction' => 'no_action_false_positive',
            'waive' => true,
        ],
        'GTIN_SERIAL_MISMATCH' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'INVALID_COMPANY_PREFIX' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'INVALID_GTIN_CHECK_DIGIT' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'INVALID_SSCC_CHECK_DIGIT' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'LOT_MISMATCH' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'QUANTITY_MISMATCH' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'PARTIAL_SHIPMENT_UNDECLARED' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
            'waive' => true,
        ],
        'OVER_SHIPMENT' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
            'quarantine' => true,
            'waive' => false,
        ],
        'MIXED_EXPIRY_SAME_LOT' => [
            'rootCause' => 'partner_data_error',
            'resolutionAction' => 'request_partner_correction',
        ],
        'DUPLICATE_SERIAL' => [
            'rootCause' => 'internal_mapping_error',
        ],
        'SERIAL_ALREADY_COMMISSIONED' => [
            'rootCause' => 'internal_mapping_error',
        ],

        // Likely-benign structural signals: still fixable via document tools, but also
        // waivable without a data correction.
        'LEADING_ZERO_STRIPPED' => ['waive' => true],
        'ENCODING_ERROR' => ['waive' => true],
        'STALE_EVENT' => ['waive' => true],
        'UNSUPPORTED_EPC_TYPE' => ['waive' => true],
        'INVALID_EXTENSION_NAMESPACE' => ['waive' => true],
        'FILE_SIZE_EXCEEDED' => ['waive' => true],
        // Internal platform validation failures require document correct/reprocess — not waiver.
        'INTERNAL_VALIDATION_FAILED' => [
            'blurb' => 'Platform business-rule validation failed for this document. Review the validation errors, correct or replace the file, and re-process. Waiver is not available for this signal.',
            'waive' => false,
        ],
        'FINDINGS_TRUNCATED' => [
            'blurb' => 'Some findings of the same type were omitted because this document exceeded the per-type validation cap. Investigate the surfaced sample and request a corrected file if needed.',
        ],
    ];
}
