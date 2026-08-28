<?php

namespace App\Support\Marketing;

class MarketingPlatformIntegrations
{
    /**
     * Marketing-only vendor catalogs. Product PMS/WMS config classes are not in this app.
     *
     * @return array<string, array{label: string, header_name: string}>
     */
    private static function pmsVendors(): array
    {
        return [
            'pioneerrx' => ['label' => 'PioneerRx', 'header_name' => 'X-PioneerRx-Secret'],
            'bestrx' => ['label' => 'BestRx', 'header_name' => 'X-BestRx-Secret'],
            'primerx' => ['label' => 'PrimeRx', 'header_name' => 'X-PrimeRx-Secret'],
            'liberty' => ['label' => 'Liberty / Rx30', 'header_name' => 'X-Liberty-Secret'],
            'qs1' => ['label' => 'QS/1', 'header_name' => 'X-QS1-Secret'],
            'enterpriserx' => ['label' => 'EnterpriseRx', 'header_name' => 'X-EnterpriseRx-Secret'],
            'scriptpro' => ['label' => 'ScriptPro', 'header_name' => 'X-ScriptPro-Secret'],
        ];
    }

    /**
     * @return array<string, array{label: string, header_name: string}>
     */
    private static function wmsVendors(): array
    {
        return [
            'manhattan' => ['label' => 'Manhattan', 'header_name' => 'X-Manhattan-Secret'],
            'korber' => ['label' => 'Körber', 'header_name' => 'X-Korber-Secret'],
        ];
    }

    /**
     * @return array<string, array{label: string, transport: string, wholesaler_template: string, partner_adapter: string, checklist: list<string>, copy_lines: list<string>}>
     */
    private static function wholesalePresets(): array
    {
        return [
            'cardinal' => [
                'label' => 'Cardinal Health',
                'transport' => 'sftp',
                'wholesaler_template' => 'Cardinal Health',
                'partner_adapter' => 'cardinal',
                'checklist' => [
                    'Request SFTP credentials from Cardinal DSCSA onboarding.',
                    'Apply the Cardinal SFTP inbound preset in TracePharma.',
                    'Test with a sample EPCIS shipment before cutover.',
                ],
                'copy_lines' => ['Preset: cardinal_sftp'],
            ],
            'mckesson-as2' => [
                'label' => 'McKesson (AS2)',
                'transport' => 'as2',
                'wholesaler_template' => 'McKesson',
                'partner_adapter' => 'mckesson',
                'checklist' => [
                    'Exchange AS2 certificates with McKesson.',
                    'Apply the McKesson AS2 inbound preset.',
                    'Validate a test shipment before production.',
                ],
                'copy_lines' => ['Preset: mckesson_as2'],
            ],
            'mckesson-https' => [
                'label' => 'McKesson (HTTPS)',
                'transport' => 'https',
                'wholesaler_template' => 'McKesson',
                'partner_adapter' => 'mckesson',
                'checklist' => [
                    'Obtain McKesson HTTPS endpoint credentials.',
                    'Apply the McKesson HTTPS inbound preset.',
                    'Validate a test shipment before production.',
                ],
                'copy_lines' => ['Preset: mckesson_https'],
            ],
            'cencora' => [
                'label' => 'Cencora (AmerisourceBergen)',
                'transport' => 'sftp',
                'wholesaler_template' => 'Cencora',
                'partner_adapter' => 'cencora',
                'checklist' => [
                    'Request SFTP credentials from Cencora DSCSA onboarding.',
                    'Apply the Cencora SFTP inbound preset.',
                    'Test with a sample EPCIS shipment before cutover.',
                ],
                'copy_lines' => ['Preset: amerisourcebergen_sftp'],
            ],
            'morris-dickson' => [
                'label' => 'Morris & Dickson',
                'transport' => 'https',
                'wholesaler_template' => 'Morris & Dickson',
                'partner_adapter' => 'morris_dickson',
                'checklist' => [
                    'Obtain Morris & Dickson HTTPS credentials.',
                    'Apply the Morris & Dickson HTTPS inbound preset.',
                    'Validate a test shipment before production.',
                ],
                'copy_lines' => ['Preset: morris_dickson_https'],
            ],
        ];
    }
    /**
     * @return list<string>
     */
    public static function pmsSlugs(): array
    {
        return array_keys(self::pmsDefinitions());
    }

    /**
     * @return list<string>
     */
    public static function wmsSlugs(): array
    {
        return array_keys(self::wmsDefinitions());
    }

    /**
     * @return list<string>
     */
    public static function wholesaleSlugs(): array
    {
        return array_keys(self::wholesaleDefinitions());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pmsDefinitions(): array
    {
        $definitions = [];

        foreach (self::pmsVendors() as $key => $vendor) {
            $label = $vendor['label'];
            $definitions[$key] = [
                'slug' => $key,
                'name' => $label,
                'category' => 'Pharmacy PMS',
                'pulse_listed' => false,
                'preset' => $key,
                'transports' => ['HTTPS REST'],
                'meta_description' => "Connect {$label} to TracePharma for DSCSA dispense-check—block fills until VRS verification passes via POST /api/v1/dispense-check.",
                'hero_description' => "TracePharma exposes a single dispense-check API that {$label} middleware can call before completing a fill. Named per-vendor PMS adapter routes are not GA; unverified or failed serials block dispense with a logged reason.",
                'summary' => "{$label} remains your pharmacy system of record. TracePharma is the L4 traceability hub—receiving wholesaler EPCIS, running VRS verification, and gating dispense through POST /api/v1/dispense-check (not a per-vendor /api/v1/pms/{$key}/dispense route).",
                'inbound' => [
                    "{$label} middleware POSTs to POST /api/v1/dispense-check with GTIN, serial, and optional barcode fields.",
                    'TracePharma validates prior verification state or runs VRS when configured.',
                    'Blocked dispenses return structured reasons for pharmacist workflow and audit.',
                ],
                'outbound' => [
                    'Dispense outcomes feed the dispenser scorecard and verification audit trail.',
                    'Dispenser scorecard with blocked-reason trends for compliance review.',
                    'GET /api/v1/compliance/dispenser-scorecard for BI.',
                ],
                'cutover' => [
                    'Enable dispense-check integration and issue a Sanctum token with vrs:dispense-check.',
                    "Point {$label} middleware at POST /api/v1/dispense-check on your tenant domain.",
                    'Named per-vendor PMS adapters are not GA—use the unified dispense-check endpoint.',
                    'Test with a verified GTIN+serial before production fills.',
                ],
                'best_for' => [
                    "Independent and small-chain pharmacies on {$label}.",
                    'Teams requiring dispense-time DSCSA gates without replacing the PMS.',
                    'Pharmacies pairing wholesaler EPCIS receiving with workstation verification.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => "Does TracePharma replace {$label}?",
                        'answer' => "No. {$label} continues to manage prescriptions and inventory. TracePharma provides DSCSA verification and dispense-check gating via POST /api/v1/dispense-check.",
                    ],
                    [
                        'question' => 'What happens when verification fails?',
                        'answer' => 'The dispense-check API returns a blocked response with a reason code. The event is logged for FDA 3911 and dispenser scorecard reporting.',
                    ],
                    [
                        'question' => "Is there a dedicated {$label} adapter route?",
                        'answer' => 'Not as GA. Marketing pages may mention common PMS vendors as integration targets; the shipped surface is the single POST /api/v1/dispense-check endpoint.',
                    ],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function wmsDefinitions(): array
    {
        $definitions = [];

        foreach (self::wmsVendors() as $key => $vendor) {
            $label = $vendor['label'];
            $definitions[$key] = [
                'slug' => $key,
                'name' => $label,
                'category' => 'Warehouse management (WMS)',
                'pulse_listed' => false,
                'preset' => $key,
                'transports' => ['HTTPS webhook'],
                'meta_description' => "Connect {$label} to TracePharma WMS ship-confirm—turn warehouse ship events into outbound EPCIS with audit trail and operations scorecard.",
                'hero_description' => "TracePharma receives ship-confirm callbacks from {$label} and normalizes them into outbound EPCIS shipment drafts—without replacing your WMS.",
                'summary' => "{$label} continues to drive pick/pack/ship. TracePharma is your L4 hub. Ship-confirm webhooks trigger EPCIS generation, ACK monitoring, and exception workflows when customer GLNs or serial data are missing.",
                'inbound' => [
                    "POST /api/webhooks/wms/{tenantId}/{$key}/ship-confirm with ship-confirm JSON payload.",
                    "Optional {$vendor['header_name']} shared-secret authentication.",
                    'Payload customer GLN matched to authorized trading partners before queue.',
                ],
                'outbound' => [
                    'Outbound EPCIS documents generated from normalized ship-confirm data.',
                    'Optional auto-queue for partner delivery after validation.',
                    'wms_ship_confirm_events audit with operations scorecard blocked-reason trends.',
                ],
                'cutover' => [
                    'Enable WMS ship-confirm integration in Tenant Settings.',
                    "Configure shared secret for {$label} if required.",
                    'Point WMS middleware at your tenant ship-confirm webhook URL.',
                    'Ensure customer GLN in payload matches a trading partner record.',
                ],
                'best_for' => [
                    "Regional wholesalers and 3PLs running {$label}.",
                    'DC teams that want EPCIS outbound triggered from WMS ship-confirm—not manual re-entry.',
                    'Operations leads monitoring blocked ship-confirms on the operations scorecard.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => "Does TracePharma replace {$label}?",
                        'answer' => "No. {$label} remains your WMS. TracePharma consumes ship-confirm events and produces DSCSA-compliant outbound EPCIS.",
                    ],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function wholesaleDefinitions(): array
    {
        foreach (self::wholesalePresets() as $slug => $preset) {
            $transport = match ($preset['transport']) {
                'as2' => 'AS2',
                'https' => 'HTTPS',
                default => 'SFTP',
            };

            $presetKey = $preset['copy_lines'][0] ?? $slug;
            $presetKey = str_replace('Preset: ', '', $presetKey);

            $definitions[$slug] = [
                'slug' => $slug,
                'name' => $preset['label'],
                'category' => 'Drug wholesaler EPCIS',
                'pulse_listed' => false,
                'preset' => $presetKey,
                'transports' => [$transport],
                'meta_description' => "Receive {$preset['label']} EPCIS shipments in TracePharma—wholesaler preset with cutover checklist for DSCSA receiving.",
                'hero_description' => "TracePharma ships an inbound connection preset for {$preset['label']}. Receive EPCIS, match 3T data, and surface exceptions before inventory—using the transport channel your wholesaler supports.",
                'summary' => "Most dispensers and distributors receive serialized product from {$preset['wholesaler_template']} via {$transport}. TracePharma onboarding wizard applies this preset. Operators focus on receiving workflows—not connector configuration from scratch.",
                'inbound' => [
                    "{$transport} inbound preset ({$presetKey}) with partner adapter {$preset['partner_adapter']}.",
                    'EPCIS shipment files parsed into scan-first receiving and 3T matching.',
                    'Exceptions surfaced when serials or transaction data are missing from the file.',
                ],
                'outbound' => [
                    'Not applicable for typical pharmacy receive-only workflows.',
                    'Distributors may ship downstream EPCIS from the same TracePharma tenant.',
                    'VRS verification and FDA 3911 from received serial inventory.',
                ],
                'cutover' => $preset['checklist'],
                'best_for' => [
                    'Pharmacies receiving from '.$preset['label'].'.',
                    'Distributors ingesting upstream '.$transport.' EPCIS feeds.',
                    'Teams replacing portal-only TI/TH/TS storage with serial-level receiving.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Do I need a separate wholesaler portal if I use TracePharma?',
                        'answer' => 'TracePharma ingests EPCIS and matches transaction data—the accountability record for DSCSA. Many teams keep the wholesaler portal during transition, then rely on TracePharma for serial-level receiving and verification audit trails.',
                    ],
                ],
                'copy_lines' => $preset['copy_lines'],
            ];
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function erpSlugs(): array
    {
        return array_keys(self::erpDefinitions());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function erpDefinitions(): array
    {
        return [
            'oracle' => [
                'slug' => 'oracle',
                'name' => 'Oracle ERP Cloud',
                'category' => 'ERP adjacency',
                'pulse_listed' => false,
                'preset' => null,
                'transports' => ['REST API v1', 'HTTPS webhooks'],
                'meta_description' => 'TracePharma alongside Oracle ERP Cloud—L4 DSCSA via REST capture and webhooks without replacing your Oracle system of record.',
                'hero_description' => 'Oracle ERP Cloud remains your system of record for orders, inventory, and finance. TracePharma is the L4 DSCSA application layer that speaks EPCIS to trading partners—integrated via middleware or WMS ship-confirm bridges.',
                'summary' => 'No certified Oracle connector ships today. Most life-sciences distributors on Oracle integrate TracePharma through iPaaS (Boomi, MuleSoft, OIC) mapping ship events to POST /api/v1/epcis/capture. Use Manhattan/Körber WMS ship-confirm webhooks when fulfillment runs in the warehouse layer.',
                'inbound' => [
                    'POST /api/v1/epcis/capture for middleware-delivered EPCIS payloads.',
                    'Partner AS2/SFTP paths bypass Oracle for traceability file delivery.',
                    'WMS ship-confirm webhook triggers outbound EPCIS when Oracle → WMS → dock.',
                ],
                'outbound' => [
                    'Outbound webhooks for verification, exceptions, and ACK failures.',
                    'Optional push to Oracle staging tables or external data lake via iPaaS.',
                    'Compliance exports for audits independent of Oracle financial posting.',
                ],
                'cutover' => [
                    'Map where serial custody events originate—ERP, WMS, or partner EPCIS only.',
                    'Choose WMS-bridge vs middleware-first pattern with your SI partner.',
                    'Test capture endpoint with sample EPCIS before production promotion.',
                    'Use the dedicated SAP integration page if you run SAP ATTP.',
                ],
                'best_for' => [
                    'Life-sciences distributors on Oracle Cloud SCM without SAP ATTP.',
                    'Manufacturers using Oracle for orders but WMS for ship execution.',
                    'Teams needing partner-edge L4 without funding global serialization modules.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Is there a native Oracle ERP connector?',
                        'answer' => 'No certified Oracle connector ships today. Integrate via REST API capture, outbound webhooks, and middleware—or use the WMS ship-confirm bridge when warehouse systems sit between Oracle and the dock.',
                    ],
                    [
                        'question' => 'Does TracePharma replace Oracle ERP?',
                        'answer' => 'No. Oracle remains the system of record for POs, inventory valuation, and financial posting. TracePharma handles DSCSA partner-edge workflows.',
                    ],
                ],
            ],
            'netsuite' => [
                'slug' => 'netsuite',
                'name' => 'NetSuite',
                'category' => 'ERP adjacency',
                'pulse_listed' => false,
                'preset' => null,
                'transports' => ['REST API v1', 'HTTPS webhooks'],
                'meta_description' => 'TracePharma alongside NetSuite—mid-market L4 DSCSA via REST capture and webhooks without a certified SuiteApp connector.',
                'hero_description' => 'NetSuite manages orders, inventory, and finance for many mid-market pharma and dental distributors. TracePharma adds the L4 DSCSA layer—EPCIS receive/ship, VRS, and exceptions—via API and webhook adjacency.',
                'summary' => 'No NetSuite SuiteApp or certified connector ships today. Typical patterns: Celigo/Boomi SuiteScript middleware POSTs EPCIS to TracePharma capture endpoints. Use WMS ship-confirm webhooks when fulfillment runs in Manhattan/Körber instead of NetSuite alone.',
                'inbound' => [
                    'POST /api/v1/epcis/capture from iPaaS or custom SuiteScript services.',
                    'Wholesaler AS2/SFTP presets deliver partner EPCIS directly to TracePharma.',
                    'Receiving matches scans to EPCIS without requiring NetSuite custom records.',
                ],
                'outbound' => [
                    'Outbound webhooks push verification and exception events to NetSuite or BI.',
                    'Dispenser scorecard and compliance exports for pharmacy profiles.',
                    'Optional custom record mapping via middleware—not a native SuiteApp.',
                ],
                'cutover' => [
                    'Identify whether ship events originate in NetSuite, WMS, or partner EPCIS only.',
                    'Configure middleware or WMS webhook path with your integration partner.',
                    'Test dispense-check and receiving on pilot SKUs before full cutover.',
                ],
                'best_for' => [
                    'Regional distributors and SMB manufacturers on NetSuite.',
                    'Dental and medical supply companies evaluating DSCSA alongside SuiteCommerce.',
                    'Teams wanting L4 SaaS without replacing NetSuite as financial system of record.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Is there a NetSuite SuiteApp for TracePharma?',
                        'answer' => 'No certified SuiteApp ships today. Integrate via REST API capture, outbound webhooks, and middleware such as Celigo or Boomi.',
                    ],
                    [
                        'question' => 'Can NetSuite replace EPCIS receiving?',
                        'answer' => 'No. NetSuite handles business transactions; DSCSA interoperable traceability requires EPCIS (or equivalent) at the partner edge—handled by TracePharma.',
                    ],
                ],
            ],
            'dynamics365' => [
                'slug' => 'dynamics365',
                'name' => 'Microsoft Dynamics 365',
                'category' => 'ERP adjacency',
                'pulse_listed' => false,
                'preset' => null,
                'transports' => ['REST API v1', 'HTTPS webhooks'],
                'meta_description' => 'TracePharma alongside Microsoft Dynamics 365—L4 DSCSA via REST capture and webhooks without a certified Dynamics connector or ISV package.',
                'hero_description' => 'Dynamics 365 Supply Chain Management and Business Central remain your system of record for orders, inventory, and finance. TracePharma is the L4 DSCSA application layer that speaks EPCIS to trading partners—integrated via Power Automate, Logic Apps, or WMS ship-confirm bridges.',
                'summary' => 'No certified Dynamics 365 connector or AppSource package ships today. Typical patterns: Azure Logic Apps or Power Automate flows POST EPCIS to TracePharma capture endpoints. Use Manhattan/Körber WMS ship-confirm webhooks when fulfillment runs outside D365 at the warehouse layer.',
                'inbound' => [
                    'POST /api/v1/epcis/capture from Logic Apps, Power Automate, or custom Azure Functions.',
                    'Partner AS2/SFTP paths deliver EPCIS directly to TracePharma—bypassing D365 for trace payloads.',
                    'WMS ship-confirm webhook triggers outbound EPCIS when D365 → WMS → dock.',
                ],
                'outbound' => [
                    'Outbound webhooks for verification, exceptions, and ACK failures to Dataverse or BI.',
                    'Optional staging to Azure Data Lake or D365 custom entities via middleware.',
                    'Compliance exports for audits independent of D365 financial posting.',
                ],
                'cutover' => [
                    'Map where serial custody events originate—D365, WMS, or partner EPCIS only.',
                    'Choose WMS-bridge vs Logic Apps-first pattern with your SI partner.',
                    'Test capture endpoint with sample EPCIS before production promotion.',
                    'Use the dedicated SAP integration page if you run SAP ATTP.',
                ],
                'best_for' => [
                    'Mid-market distributors on Dynamics 365 SCM or Business Central.',
                    'Manufacturers using D365 for orders but WMS for ship execution.',
                    'Teams needing partner-edge L4 without funding global serialization ERP modules.',
                ],
                'compare_route' => null,
                'faq' => [
                    [
                        'question' => 'Is there a native Dynamics 365 connector for TracePharma?',
                        'answer' => 'No certified Dynamics connector or AppSource package ships today. Integrate via REST API capture, outbound webhooks, and Azure middleware—or use the WMS ship-confirm bridge when warehouse systems sit between D365 and the dock.',
                    ],
                    [
                        'question' => 'Does TracePharma replace Dynamics 365?',
                        'answer' => 'No. Dynamics 365 remains the system of record for POs, inventory valuation, and financial posting. TracePharma handles DSCSA partner-edge workflows.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getErp(string $slug): array
    {
        return self::getFrom(self::erpDefinitions(), $slug, 'ERP');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPms(string $slug): array
    {
        return self::getFrom(self::pmsDefinitions(), $slug, 'PMS');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getWms(string $slug): array
    {
        return self::getFrom(self::wmsDefinitions(), $slug, 'WMS');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getWholesale(string $slug): array
    {
        return self::getFrom(self::wholesaleDefinitions(), $slug, 'wholesale');
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, mixed>
     */
    private static function getFrom(array $definitions, string $slug, string $type): array
    {
        if (! isset($definitions[$slug])) {
            throw new \InvalidArgumentException("Unknown marketing {$type} integration: {$slug}");
        }

        return $definitions[$slug];
    }
}
