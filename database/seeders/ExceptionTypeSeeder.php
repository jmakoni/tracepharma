<?php

namespace Database\Seeders;

use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionTypeCategory;
use App\Models\Exceptions\ExceptionType;
use App\Support\Exceptions\ExceptionReceiveImpactMap;
use Illuminate\Database\Seeder;

class ExceptionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = $this->catalog();

        foreach ($catalog as $row) {
            ExceptionType::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'hda_class' => $row['hda_class'],
                    'description' => $row['description'],
                    'default_severity' => $row['default_severity'],
                    'receive_impact' => $row['receive_impact'],
                    'is_active' => true,
                ],
            );
        }

        $codes = array_column($catalog, 'code');
        $count = ExceptionType::query()->whereIn('code', $codes)->count();

        // Deactivate pre-catalog lowercase leftovers so UI/type filters stay clean.
        ExceptionType::query()
            ->whereNotIn('code', $codes)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $this->command?->info("Exception types in catalog: {$count}");
    }

    public static function ensure(string $code): ?ExceptionType
    {
        $code = strtoupper(trim($code));
        $seeder = new self();

        foreach ($seeder->catalog() as $row) {
            if ($row['code'] !== $code) {
                continue;
            }

            return ExceptionType::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'hda_class' => $row['hda_class'],
                    'description' => $row['description'],
                    'default_severity' => $row['default_severity'],
                    'receive_impact' => $row['receive_impact'],
                    'is_active' => true,
                ],
            );
        }

        return null;
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     category: ExceptionTypeCategory,
     *     hda_class: ?string,
     *     description: string,
     *     default_severity: ExceptionSeverity,
     *     receive_impact: ExceptionReceiveImpact
     * }>
     */
    private function catalog(): array
    {
        return [
            // Identifier & Master Data
            $this->row('DUPLICATE_SERIAL', 'Duplicate Serial', ExceptionTypeCategory::Identifier, ExceptionSeverity::Critical, 'Same SGTIN appears more than once as active', 'data_issues'),
            $this->row('SERIAL_ALREADY_COMMISSIONED', 'Serial Already Commissioned', ExceptionTypeCategory::Identifier, ExceptionSeverity::Critical, 'Attempt to commission a serial that already exists', 'data_issues'),
            $this->row('UNKNOWN_GTIN', 'Unknown / Unregistered GTIN', ExceptionTypeCategory::MasterData, ExceptionSeverity::High, 'GTIN not found in internal or partner master data', 'data_issues'),
            $this->row('INVALID_GTIN_CHECK_DIGIT', 'Invalid GTIN Check Digit', ExceptionTypeCategory::MasterData, ExceptionSeverity::High, 'GTIN fails GS1 check digit validation', 'data_issues'),
            $this->row('INVALID_SSCC_CHECK_DIGIT', 'Invalid SSCC Check Digit', ExceptionTypeCategory::MasterData, ExceptionSeverity::High, 'SSCC fails check digit validation', 'data_issues'),
            $this->row('UNKNOWN_GLN', 'Unknown / Unrecognized GLN', ExceptionTypeCategory::MasterData, ExceptionSeverity::High, 'GLN not recognized by system or trading partner', 'data_issues'),
            $this->row('INVALID_COMPANY_PREFIX', 'Invalid Company Prefix Length', ExceptionTypeCategory::MasterData, ExceptionSeverity::High, 'Company Prefix length does not match GCP rules', 'data_issues'),
            $this->row('LEADING_ZERO_STRIPPED', 'Leading Zeros Stripped', ExceptionTypeCategory::MasterData, ExceptionSeverity::Medium, 'Serial or lot number had leading zeros removed', 'data_issues'),
            $this->row('GTIN_SERIAL_MISMATCH', 'GTIN + Serial Combination Conflict', ExceptionTypeCategory::Identifier, ExceptionSeverity::High, 'Same serial used on different GTINs', 'data_issues'),
            $this->row('INVALID_EPC_URI', 'Malformed EPC URI', ExceptionTypeCategory::Identifier, ExceptionSeverity::High, 'EPC URI does not conform to TDS syntax', 'data_issues'),
            $this->row('UNSUPPORTED_EPC_TYPE', 'Unsupported EPC Type', ExceptionTypeCategory::Identifier, ExceptionSeverity::Medium, 'EPC type not supported by the platform', 'data_issues'),

            // Event Structure & Content
            $this->row('MISSING_MANDATORY_FIELD', 'Missing Mandatory Field', ExceptionTypeCategory::EventStructure, ExceptionSeverity::High, 'Required EPCIS field is missing', 'data_issues'),
            $this->row('INVALID_BIZSTEP', 'Invalid or Non-CBV bizStep', ExceptionTypeCategory::EventStructure, ExceptionSeverity::Medium, 'bizStep not in Core Business Vocabulary', 'data_issues'),
            $this->row('INVALID_DISPOSITION', 'Invalid or Non-CBV Disposition', ExceptionTypeCategory::EventStructure, ExceptionSeverity::Medium, 'Disposition value not recognized', 'data_issues'),
            $this->row('FUTURE_EVENT_TIME', 'Future Event Time', ExceptionTypeCategory::EventStructure, ExceptionSeverity::High, 'eventTime is in the future', 'data_issues'),
            $this->row('STALE_EVENT', 'Stale Event', ExceptionTypeCategory::EventStructure, ExceptionSeverity::Medium, 'eventTime is unreasonably old compared to recordTime', 'data_issues'),
            $this->row('INVALID_ACTION', 'Invalid Action Value', ExceptionTypeCategory::EventStructure, ExceptionSeverity::Medium, 'Action is not ADD, OBSERVE, or DELETE', 'data_issues'),
            $this->row('DELETE_WITHOUT_PRIOR_ADD', 'DELETE without Prior ADD/OBSERVE', ExceptionTypeCategory::EventStructure, ExceptionSeverity::High, 'DELETE action with no corresponding previous event', 'data_issues'),
            $this->row('MISSING_DSCSA_STATEMENT', 'Missing DSCSA Transaction Statement', ExceptionTypeCategory::EventStructure, ExceptionSeverity::High, 'affirmTransactionStatement missing or false', 'product_no_data'),
            $this->row('INVALID_EXTENSION_NAMESPACE', 'Invalid Extension Namespace', ExceptionTypeCategory::EventStructure, ExceptionSeverity::Medium, 'Extension uses incorrect or unknown namespace', 'data_issues'),
            $this->row('MIXED_PACKAGING_LEVELS', 'Mixed Packaging Levels in epcList', ExceptionTypeCategory::EventStructure, ExceptionSeverity::Medium, 'Different packaging levels mixed incorrectly', 'packaging_labeling'),

            // Aggregation & Hierarchy
            $this->row('BROKEN_AGGREGATION', 'Broken Aggregation Hierarchy', ExceptionTypeCategory::Aggregation, ExceptionSeverity::High, 'Parent-child relationship is inconsistent', 'packaging_labeling'),
            $this->row('MISSING_PARENT', 'Children without Parent', ExceptionTypeCategory::Aggregation, ExceptionSeverity::High, 'Child EPCs present with no parent', 'packaging_labeling'),
            $this->row('MISSING_CHILDREN', 'Parent without Children', ExceptionTypeCategory::Aggregation, ExceptionSeverity::High, 'Parent declared but no children found', 'packaging_labeling'),
            $this->row('AGGREGATION_QUANTITY_MISMATCH', 'Aggregation Quantity Mismatch', ExceptionTypeCategory::Aggregation, ExceptionSeverity::High, 'Declared quantity ≠ actual number of children', 'data_issues'),
            $this->row('MULTIPLE_PARENTS', 'Child under Multiple Parents', ExceptionTypeCategory::Aggregation, ExceptionSeverity::Critical, 'Same child appears under more than one parent', 'data_issues'),
            $this->row('ORPHAN_SSCC', 'Orphan SSCC', ExceptionTypeCategory::Aggregation, ExceptionSeverity::High, 'SSCC commissioned but never aggregated', 'data_issues'),
            $this->row('HIERARCHY_DEPTH_EXCEEDED', 'Hierarchy Depth Exceeded', ExceptionTypeCategory::Aggregation, ExceptionSeverity::Medium, 'Aggregation depth exceeds system/partner limits', 'packaging_labeling'),
            $this->row('PACKAGING_TYPE_CONFLICT', 'Packaging Type Conflict', ExceptionTypeCategory::Aggregation, ExceptionSeverity::Medium, 'Extension packaging type conflicts with AggregationEvent', 'packaging_labeling'),
            $this->row('DEAGGREGATION_WITHOUT_PRIOR', 'De-aggregation without Prior Aggregation', ExceptionTypeCategory::Aggregation, ExceptionSeverity::High, 'DELETE aggregation with no matching ADD', 'data_issues'),

            // Quantity, Lot & Expiry
            $this->row('LOT_MISMATCH', 'Lot Number Mismatch', ExceptionTypeCategory::Quantity, ExceptionSeverity::High, 'Lot on shipping event differs from commissioning', 'data_issues'),
            $this->row('QUANTITY_MISMATCH', 'Quantity Mismatch', ExceptionTypeCategory::Quantity, ExceptionSeverity::High, 'Event quantity does not match physical or ASN', 'data_issues'),
            $this->row('MISSING_EXPIRY', 'Missing Expiry Date', ExceptionTypeCategory::Quantity, ExceptionSeverity::High, 'Expiry date required but not present', 'data_issues'),
            $this->row('EXPIRED_PRODUCT_SHIPPED', 'Expired Product Shipped', ExceptionTypeCategory::Quantity, ExceptionSeverity::Critical, 'Product shipped after expiry date', 'unavailable_for_distribution'),
            $this->row('MIXED_EXPIRY_SAME_LOT', 'Mixed Expiry Dates in Same Lot', ExceptionTypeCategory::Quantity, ExceptionSeverity::High, 'Different expiry dates for serials in same lot', 'data_issues'),
            $this->row('PARTIAL_SHIPMENT_UNDECLARED', 'Undeclared Partial Shipment', ExceptionTypeCategory::Quantity, ExceptionSeverity::Medium, 'Shipped quantity lower than commissioned without notice', 'data_no_product'),
            $this->row('OVER_SHIPMENT', 'Over Shipment', ExceptionTypeCategory::Quantity, ExceptionSeverity::High, 'Shipped quantity higher than available', 'product_no_data'),

            // Timing & Sequence
            $this->row('TIMING_INVERSION', 'Timing Inversion', ExceptionTypeCategory::Timing, ExceptionSeverity::High, 'Commissioning event after shipping event', 'data_issues'),
            $this->row('COMMISSION_AFTER_SHIP', 'Commissioned After Shipping', ExceptionTypeCategory::Timing, ExceptionSeverity::Critical, 'Serial commissioned after it was already shipped', 'data_issues'),
            $this->row('EVENTS_OUT_OF_ORDER', 'Events Out of Chronological Order', ExceptionTypeCategory::Timing, ExceptionSeverity::Medium, 'Events received in wrong sequence', 'data_issues'),
            $this->row('SHIP_BEFORE_COMMISSION', 'Shipped Before Commissioning', ExceptionTypeCategory::Timing, ExceptionSeverity::Critical, 'Shipping event exists with no prior commissioning', 'product_no_data'),
            $this->row('DECOMMISSION_AFTER_SHIP', 'Decommissioned After Shipping', ExceptionTypeCategory::Timing, ExceptionSeverity::High, 'Serial decommissioned after it left the facility', 'data_issues'),

            // Transmission & Partner
            $this->row('PARTNER_REJECTED_FILE', 'Partner Rejected File', ExceptionTypeCategory::Transmission, ExceptionSeverity::High, 'Trading partner rejected the EPCIS document', 'data_issues'),
            $this->row('MISSING_MDN', 'Missing MDN', ExceptionTypeCategory::Transmission, ExceptionSeverity::Medium, 'No Message Delivery Notification received', null),
            $this->row('LATE_MDN', 'Late MDN', ExceptionTypeCategory::Transmission, ExceptionSeverity::Medium, 'MDN received after expected window', null),
            $this->row('DUPLICATE_TRANSMISSION', 'Duplicate Transmission', ExceptionTypeCategory::Transmission, ExceptionSeverity::Medium, 'Same document/event sent more than once', 'data_issues'),
            $this->row('FILE_SIZE_EXCEEDED', 'File Size Exceeded Partner Limit', ExceptionTypeCategory::Transmission, ExceptionSeverity::Medium, 'Document larger than partner accepts', null),
            $this->row('ENCODING_ERROR', 'Encoding / Character Set Error', ExceptionTypeCategory::Transmission, ExceptionSeverity::Medium, 'File encoding issues', 'data_issues'),
            $this->row('MISSING_SOURCE_DESTINATION', 'Missing Source/Destination', ExceptionTypeCategory::Transmission, ExceptionSeverity::High, 'Required sourceList or destinationList missing', 'data_issues'),
            $this->row('SBDH_SOURCE_OWNING_PARTY_MISMATCH', 'SBDH / Source Owning Party Mismatch', ExceptionTypeCategory::Transmission, ExceptionSeverity::Medium, 'SBDH Sender GLN does not match shipping event source owning_party GLN', 'data_issues'),
            $this->row('MISSING_BIZ_TRANSACTION', 'Missing Business Transaction Reference', ExceptionTypeCategory::Transmission, ExceptionSeverity::Medium, 'PO/ASN reference missing when required', 'data_issues'),

            // Process & DSCSA Compliance
            $this->row('MISSING_COMMISSIONING', 'Missing Commissioning Event', ExceptionTypeCategory::Process, ExceptionSeverity::Critical, 'Serial exists in supply chain with no commissioning', 'product_no_data'),
            $this->row('SERIAL_SHIPPED_NOT_COMMISSIONED', 'Serial Shipped but Never Commissioned', ExceptionTypeCategory::Process, ExceptionSeverity::Critical, 'High regulatory risk', 'product_no_data'),
            $this->row('DECOMMISSIONED_SERIAL_SHIPPED', 'Decommissioned Serial Shipped', ExceptionTypeCategory::Process, ExceptionSeverity::Critical, 'Serial was decommissioned then shipped', 'unavailable_for_distribution'),
            $this->row('SUSPECT_PRODUCT', 'Suspect Product Indicator', ExceptionTypeCategory::Process, ExceptionSeverity::Critical, 'Potential illegitimate product', 'unavailable_for_distribution'),
            $this->row('VERIFICATION_FAILED', 'Verification Request Failed', ExceptionTypeCategory::Process, ExceptionSeverity::High, 'Trading partner verification failed', 'unavailable_for_distribution'),
            $this->row('RETURNS_NOT_LINKED', 'Returns Not Linked to Original Chain', ExceptionTypeCategory::Process, ExceptionSeverity::High, 'Return event not properly linked', 'data_issues'),
            $this->row('DROP_SHIPMENT_INDICATOR_MISSING', 'Drop Shipment Indicator Missing', ExceptionTypeCategory::Process, ExceptionSeverity::Medium, 'Required by GS1 US R1.3 in some flows', 'data_issues'),
            $this->row('OWNERSHIP_TRANSFER_UNCLEAR', 'Unclear Ownership Transfer', ExceptionTypeCategory::Process, ExceptionSeverity::High, 'from_custodian / to_custodian missing or conflicting', 'data_issues'),

            // System / Operational
            $this->row('L2_L3_RECONCILIATION_FAILURE', 'L2–L3 Reconciliation Failure', ExceptionTypeCategory::System, ExceptionSeverity::High, 'Packaging line counts do not match L3 events', null),
            $this->row('L3_TRANSMISSION_FAILURE', 'L3 Transmission Failure', ExceptionTypeCategory::System, ExceptionSeverity::High, 'Events generated but failed to send', null),
            $this->row('AUTO_DECOMMISSION_FAILED', 'Automated Decommissioning Failed', ExceptionTypeCategory::System, ExceptionSeverity::Medium, 'Printed-but-not-shipped units not decommissioned', null),
            $this->row('MASTER_DATA_SYNC_LAG', 'Master Data Sync Lag', ExceptionTypeCategory::System, ExceptionSeverity::Medium, 'GTIN/GLN master data out of date', 'data_issues'),
            $this->row('INGESTION_PARSE_ERROR', 'Ingestion Parse Error', ExceptionTypeCategory::System, ExceptionSeverity::High, 'XML/JSON could not be parsed', 'data_issues'),
            $this->row('INTERNAL_VALIDATION_FAILED', 'Internal Business Rule Validation Failed', ExceptionTypeCategory::System, ExceptionSeverity::High, 'Failed platform-specific business rules', 'data_issues'),

            // Fallback
            $this->row('UNCLASSIFIED', 'Unclassified', ExceptionTypeCategory::System, ExceptionSeverity::Medium, 'Fallback type for unmapped ingest signals', null),
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     category: ExceptionTypeCategory,
     *     hda_class: ?string,
     *     description: string,
     *     default_severity: ExceptionSeverity,
     *     receive_impact: ExceptionReceiveImpact
     * }
     */
    private function row(
        string $code,
        string $name,
        ExceptionTypeCategory $category,
        ExceptionSeverity $severity,
        string $description,
        ?string $hdaClass,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'hda_class' => $hdaClass,
            'description' => $description,
            'default_severity' => $severity,
            'receive_impact' => ExceptionReceiveImpactMap::forCode($code),
        ];
    }
}
