<?php

namespace App\Support\Marketing;

class GlossaryTerms
{
    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     summary: string,
     *     definition: list<string>,
     *     in_tracepharma: list<string>,
     *     related: list<string>,
     *     learn_more_route: ?string,
     *     learn_more_params: ?array<string, string>,
     *     learn_more_label: ?string
     * }>
     */
    public static function definitions(): array
    {
        return [
            'epcis' => [
                'slug' => 'epcis',
                'title' => 'EPCIS',
                'meta_description' => 'What EPCIS (Electronic Product Code Information Services) means for US DSCSA—GS1 event documents, ObjectEvents, and how TracePharma ingests them.',
                'summary' => 'GS1 standard for serial-level chain-of-custody events',
                'definition' => [
                    'EPCIS (Electronic Product Code Information Services) is a GS1 standard for sharing what happened to serialized products—who shipped which serials, when, and from which location.',
                    'Partners typically deliver EPCIS as XML or JSON containing ObjectEvent and AggregationEvent records. Under DSCSA, EPCIS is the interoperable traceability payload. It carries transaction information (TI) and transaction history (TH) alongside serial accountability.',
                    'EPCIS is not a purchase order or warehouse pick list. It answers custody questions: which GTIN+serial numbers changed hands at each step in the supply chain.',
                ],
                'in_tracepharma' => [
                    'Inbound AS2, SFTP, and HTTPS webhook presets capture partner EPCIS into unified receiving.',
                    'Scan-first receiving matches physical scans to EPCIS ObjectEvents for 3T compliance.',
                    'Outbound EPCIS generation and ACK monitoring for manufacturers and wholesalers.',
                ],
                'related' => ['asn', 'gln', 'gtin-sgtin', 'dscsa-3t'],
                'learn_more_route' => 'marketing.guides.epcis-vs-asn',
                'learn_more_label' => 'EPCIS vs ASN guide',
            ],
            'vrs' => [
                'slug' => 'vrs',
                'title' => 'VRS',
                'meta_description' => 'Verification Router Service (VRS) explained for DSCSA dispensers—how TracePharma verifies GTIN+serial before dispense and logs FDA 3911 failures.',
                'summary' => 'DSCSA serial verification service for dispensers',
                'definition' => [
                    'VRS (Verification Router Service) is the DSCSA mechanism dispensers use to verify that a product identifier (GTIN) and serial number are valid and not suspect before dispensing.',
                    'Manufacturers and repackagers publish verification endpoints; the VRS routes verification requests to the correct responder based on the GTIN prefix.',
                    'Failed verifications may require quarantine, investigation, and FDA Form 3911 reporting depending on your pharmacy or distributor workflow.',
                ],
                'in_tracepharma' => [
                    'VRS verification runs at receiving and via POST /api/v1/dispense-check with full audit trail.',
                    'PMS middleware can call the single dispense-check endpoint; named per-vendor adapter routes are not GA.',
                    'Compliance exports include verification history and FDA 3911 support for pharmacies.',
                ],
                'related' => ['gtin-sgtin', 'dscsa-3t', 'epcis'],
                'learn_more_route' => 'marketing.features.show',
                'learn_more_params' => ['feature' => 'verification'],
                'learn_more_label' => 'Verification feature',
            ],
            'dscsa-3t' => [
                'slug' => 'dscsa-3t',
                'title' => 'DSCSA 3T (TI, TH, TS)',
                'meta_description' => 'Transaction Information, Transaction History, and Transaction Statement—the three DSCSA data elements trading partners must exchange.',
                'summary' => 'TI, TH, and TS—the three accountability elements',
                'definition' => [
                    'DSCSA requires trading partners to exchange transaction information (TI), transaction history (TH), and a transaction statement (TS)—collectively called the 3T.',
                    'TI describes the product and parties in the transaction (GTIN, serial, lot, expiration, shipper, receiver). TH is the prior chain of custody for those serials. TS is a signed statement that the seller complies with DSCSA.',
                    'In practice, the 3T often arrives embedded in EPCIS events and supporting documents—not as three separate PDFs on every shipment.',
                ],
                'in_tracepharma' => [
                    'Receiving workflows validate 3T completeness against EPCIS and partner documents.',
                    'Exception investigation ties missing or inconsistent 3T to supplier accountability.',
                    'Compliance reporting exports support audits and regulator trace requests.',
                ],
                'related' => ['epcis', 'asn', 'vrs'],
                'learn_more_route' => 'marketing.compare.checklist',
                'learn_more_label' => 'DSCSA provider checklist',
            ],
            'gtin-sgtin' => [
                'slug' => 'gtin-sgtin',
                'title' => 'GTIN & SGTIN',
                'meta_description' => 'GTIN and SGTIN identifiers in US DSCSA—product codes, serial numbers, and DataMatrix barcodes explained for operators.',
                'summary' => 'Product identifier and serialized instance',
                'definition' => [
                    'GTIN (Global Trade Item Number) identifies the product SKU—typically the 14-digit identifier in a DSCSA DataMatrix barcode.',
                    'SGTIN (Serialized GTIN) combines the GTIN with a unique serial number to identify one physical unit. VRS verification and EPCIS events reference GTIN+serial pairs.',
                    'Operators scan the 2D DataMatrix at receiving and dispense; systems parse application identifiers (AI 01 for GTIN, AI 21 for serial) to build the SGTIN.',
                ],
                'in_tracepharma' => [
                    'Scan parsing extracts GTIN and serial from GS1 DataMatrix at receiving.',
                    'VRS and POST /api/v1/dispense-check validate GTIN+serial against tenant verification state.',
                    'Serialization feature covers L3 commissioning forward and outbound EPCIS commission events.',
                ],
                'related' => ['vrs', 'epcis', 'sscc'],
                'learn_more_route' => 'marketing.features.show',
                'learn_more_params' => ['feature' => 'serialization'],
                'learn_more_label' => 'Serialization feature',
            ],
            'asn' => [
                'slug' => 'asn',
                'title' => 'ASN',
                'meta_description' => 'Advance Ship Notice (ASN) vs EPCIS for DSCSA—logistics documents, EDI 856, and why receivers need both.',
                'summary' => 'Logistics ship notice—not a traceability record',
                'definition' => [
                    'ASN (Advance Ship Notice) tells the receiver what was picked and shipped—often EDI 856 or a wholesaler CSV tied to a purchase order.',
                    'ASNs help dock teams match PO lines, quantities, and lots to physical cases. They may include serial lists but are not GS1 EPCIS event documents.',
                    'DSCSA compliance requires interoperable traceability (typically EPCIS), not ASN alone. Treating an ASN as your only trace record creates gaps at verification and exception time.',
                ],
                'in_tracepharma' => [
                    'Receiving supports ASN/CSV matching alongside EPCIS for scan-first workflows.',
                    'Operators see when EPCIS is missing but ASN arrived—triggering structured exceptions.',
                    'Wholesaler presets ingest partner-specific file formats into unified receiving.',
                ],
                'related' => ['epcis', 'sscc', 'dscsa-3t'],
                'learn_more_route' => 'marketing.guides.epcis-vs-asn',
                'learn_more_label' => 'EPCIS vs ASN guide',
            ],
            'gln' => [
                'slug' => 'gln',
                'title' => 'GLN',
                'meta_description' => 'Global Location Number (GLN) in EPCIS events—ship-from, ship-to, and dispenser location identifiers for DSCSA.',
                'summary' => 'GS1 location identifier in EPCIS events',
                'definition' => [
                    'GLN (Global Location Number) is a GS1 identifier for a physical location or legal entity—warehouse, pharmacy, corporate HQ, or ship-to address.',
                    'EPCIS ObjectEvents include readPoint and bizLocation GLNs to show where custody changed. Partner authorization and Pulse directory entries also reference GLNs.',
                    'Mismatch between the GLN in EPCIS and your authorized trading partner list is a common source of receiving exceptions and ACK failures.',
                ],
                'in_tracepharma' => [
                    'Partner profiles map authorized GLNs to inbound connection presets.',
                    'Receiving validates ship-from GLN against tenant partner authorization.',
                    'Outbound EPCIS uses your tenant GLNs for wholesaler and manufacturer ship events.',
                ],
                'related' => ['epcis', 'sscc', 'dscsa-3t'],
                'learn_more_route' => 'marketing.features.show',
                'learn_more_params' => ['feature' => 'integrations'],
                'learn_more_label' => 'Integrations feature',
            ],
            'sscc' => [
                'slug' => 'sscc',
                'title' => 'SSCC',
                'meta_description' => 'Serial Shipping Container Code (SSCC)—pallet and case logistics identifiers in wholesaler receiving and EPCIS aggregation.',
                'summary' => 'Logistics container ID for pallets and cases',
                'definition' => [
                    'SSCC (Serial Shipping Container Code) identifies a logistic unit—a pallet, case, or tote—using an 18-digit GS1 identifier (AI 00 in barcodes).',
                    'EPCIS AggregationEvents link SSCCs to contained serials (parent-child hierarchy). Wholesalers scan SSCC labels at receiving to unpack aggregation trees.',
                    'SSCCs speed dock workflow but do not replace serial-level verification. Each sellable unit still needs GTIN+serial accountability under DSCSA.',
                ],
                'in_tracepharma' => [
                    'Receiving unpacks EPCIS aggregation hierarchies from SSCC to unit serials.',
                    'Scan-first workflows accept SSCC scans when partner EPCIS includes aggregation events.',
                    'Exception workflows flag broken hierarchies or missing child serials.',
                ],
                'related' => ['epcis', 'gtin-sgtin', 'asn'],
                'learn_more_route' => 'marketing.solutions.wholesalers',
                'learn_more_label' => 'Wholesaler solution',
            ],
            'fda-3911' => [
                'slug' => 'fda-3911',
                'title' => 'FDA Form 3911',
                'meta_description' => 'FDA Form 3911 for reporting suspect and illegitimate product—when dispensers and distributors must file after failed VRS verification.',
                'summary' => 'Suspect-product notification to FDA',
                'definition' => [
                    'FDA Form 3911 is the notification form trading partners use to report suspect or illegitimate product to the FDA after verification failures, counterfeiting concerns, or other DSCSA-triggered investigations.',
                    'Dispensers typically file when VRS verification fails and the product cannot be dispensed. Distributors may file when receiving investigations confirm suspect serials or broken chain-of-custody.',
                    '3911 is a compliance workflow—not a traceability transport. EPCIS and VRS provide the serial-level evidence that feeds the investigation leading to a 3911 decision.',
                ],
                'in_tracepharma' => [
                    'Verification and dispense-check workflows log failed GTIN+serial checks with audit trail.',
                    'Compliance exports include verification history to support 3911 investigations.',
                    'Exception investigation ties supplier accountability to suspect serial events.',
                ],
                'related' => ['vrs', 'gtin-sgtin', 'dscsa-3t'],
                'learn_more_route' => 'marketing.features.show',
                'learn_more_params' => ['feature' => 'compliance'],
                'learn_more_label' => 'Compliance feature',
            ],
            'atp' => [
                'slug' => 'atp',
                'title' => 'ATP (Authorized Trading Partner)',
                'meta_description' => 'Authorized Trading Partner (ATP) under DSCSA—license verification, GLN authorization, and Pulse directory concepts explained.',
                'summary' => 'Licensed, authorized supply-chain counterparty',
                'definition' => [
                    'An Authorized Trading Partner (ATP) is a licensed entity authorized to distribute or dispense prescription drugs under DSCSA—manufacturers, repackagers, wholesalers, and dispensers with valid state and federal credentials.',
                    'Trading partners must transact only with other ATPs. Partner authorization workflows map authorized GLNs and license data to inbound EPCIS and outbound ship destinations.',
                    'NABP Pulse provides a directory and API ecosystem for ATP verification and regulator communications. DSCSA compliance still requires interoperable EPCIS exchange with authorized partners.',
                ],
                'in_tracepharma' => [
                    'Partner profiles map authorized GLNs and license metadata to inbound presets.',
                    'Receiving validates ship-from GLN against tenant partner authorization.',
                    'Pulse directory API integration is on the product roadmap; EPCIS-layer cutover works today.',
                ],
                'related' => ['gln', 'dscsa-3t', 'vrs'],
                'learn_more_route' => 'marketing.integrations.nabp-pulse',
                'learn_more_label' => 'NABP Pulse status',
            ],
            'saleable-returns' => [
                'slug' => 'saleable-returns',
                'title' => 'Saleable returns',
                'meta_description' => 'DSCSA saleable returns for wholesalers—verify returned serials, accept or reject, and exchange EPCIS before resale.',
                'summary' => 'Wholesaler verification workflow for returned product',
                'definition' => [
                    'Saleable returns are product units a customer returns that may be resold if they pass verification and chain-of-custody checks under DSCSA wholesaler requirements.',
                    'Wholesalers must verify returned GTIN+serial pairs (typically via VRS), confirm transaction history, and generate or accept EPCIS before returning inventory to saleable stock.',
                    'Returns that fail verification follow quarantine and investigation paths—similar to suspect product workflows at receiving.',
                ],
                'in_tracepharma' => [
                    'Wholesaler profiles support returns verification and EPCIS exchange workflows.',
                    'VRS verification at returns intake with full audit trail.',
                    'Exception investigation links failed returns to customer and supplier accountability.',
                ],
                'related' => ['vrs', 'epcis', 'dscsa-3t'],
                'learn_more_route' => 'marketing.solutions.wholesalers',
                'learn_more_label' => 'Wholesaler solution',
            ],
            'l4' => [
                'slug' => 'l4',
                'title' => 'L4 (Level 4 traceability)',
                'meta_description' => 'Level 4 (L4) corporate EPCIS hub—partner-edge DSCSA traceability between L3 plant serialization and trading partners.',
                'summary' => 'Corporate EPCIS hub for partner exchange',
                'definition' => [
                    'L4 (Level 4) is the corporate traceability layer where serialized product custody is exchanged with trading partners via EPCIS—receive, ship, verify, and investigate exceptions across your network.',
                    'L3 covers plant-floor serialization (commission, aggregate, ship-from-manufacturer). L4 is where wholesalers, 3PLs, and manufacturers operate partner-facing workflows: inbound EPCIS, outbound EPCIS, VRS, and compliance reporting.',
                    'TracePharma is an L4 SaaS application—it does not replace ERP financial posting or L3 line systems; it handles DSCSA partner-edge accountability.',
                ],
                'in_tracepharma' => [
                    'Per-tenant workspace for EPCIS ingest, outbound generation, and ACK monitoring.',
                    'Profile-tuned navigation for manufacturers, wholesalers, 3PLs, and dispensers.',
                    'Direct partner connectivity (AS2, SFTP, HTTPS) without mandatory exchange network middlemen.',
                ],
                'related' => ['epcis', 'epcis-2-0', 'dscsa-3t'],
                'learn_more_route' => 'marketing.features.show',
                'learn_more_params' => ['feature' => 'integrations'],
                'learn_more_label' => 'Integrations feature',
            ],
            'epcis-2-0' => [
                'slug' => 'epcis-2-0',
                'title' => 'EPCIS 2.0',
                'meta_description' => 'EPCIS 2.0 and CBV 2.0 for US DSCSA—JSON-LD events, GS1 Digital Link, and how TracePharma supports 2.0 as an opt-in dual stack alongside GA EPCIS 1.2 XML.',
                'summary' => 'GS1 JSON-LD event standard; TracePharma opt-in dual stack',
                'definition' => [
                    'EPCIS 2.0 is the current GS1 standard for sharing chain-of-custody events, paired with CBV 2.0 (Core Business Vocabulary). It supports JSON-LD payloads and GS1 Digital Link URI formats for serialized identifiers.',
                    'US DSCSA interoperability increasingly expects EPCIS 2.0-capable systems for partner exchange. EPCIS 1.2 XML remains widely used and is TracePharma’s default GA path.',
                    'EPCIS 2.0 does not change DSCSA accountability requirements—it modernizes how ObjectEvents, AggregationEvents, and transaction data are encoded and queried.',
                ],
                'in_tracepharma' => [
                    'EPCIS 1.2 XML is GA for default inbound and outbound exchange.',
                    'EPCIS 2.0 JSON-LD is opt-in via TRACEPHARMA_EPCIS_ACCEPT_20 and per-partner outbound version—an event repository, not a full CBV 2.0 capture/subscriptions hub for every tenant.',
                    'Pharmacy profiles use the same event store for read-only investigation; trading-partner profiles generate outbound on the dual-stack model.',
                ],
                'related' => ['epcis', 'l4', 'dscsa-3t'],
                'learn_more_route' => 'marketing.guides.epcis-vs-asn',
                'learn_more_label' => 'EPCIS vs ASN guide',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $slug): array
    {
        $definitions = self::definitions();

        if (! isset($definitions[$slug])) {
            throw new \InvalidArgumentException("Unknown glossary term: {$slug}");
        }

        return $definitions[$slug];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(self::definitions());
    }
}
