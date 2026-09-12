<?php

namespace App\Support\Marketing;

class MarketingResources
{
    /**
     * @return list<string>
     */
    public static function blogSlugs(): array
    {
        return array_keys(self::blogPosts());
    }

    /**
     * @return list<string>
     */
    public static function caseStudySlugs(): array
    {
        return array_keys(self::caseStudies());
    }

    /**
     * @return array<string, array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     summary: string,
     *     published_at: string,
     *     sections: list<array{heading: string, paragraphs: list<string>}>,
     *     related_routes: list<array{name: string, params?: array<string, string>, label: string}>
     * }>
     */
    public static function blogPosts(): array
    {
        return [
            'scan-first-receiving-wholesalers' => [
                'slug' => 'scan-first-receiving-wholesalers',
                'title' => 'Scan-first receiving for regional wholesalers',
                'meta_description' => 'How regional drug wholesalers reconcile physical scans to inbound EPCIS for DSCSA 3T compliance—without slowing dock throughput.',
                'summary' => 'Match what your team scans to what partners send in EPCIS before you put product away.',
                'published_at' => '2026-06-15',
                'sections' => [
                    [
                        'heading' => 'Why wholesalers adopt scan-first receiving',
                        'paragraphs' => [
                            'Regional wholesalers often receive mixed shipments: some trading partners send clean EPCIS over AS2 or SFTP, others send ASN-only or delayed files. Scan-first receiving treats the physical scan as the source of truth at the dock, then matches each GTIN+serial to inbound ObjectEvents.',
                            'The goal is not to replace EPCIS—it is to close the gap between what arrived on the truck and what your L4 hub recorded before product moves to pick locations or cross-dock lanes.',
                        ],
                    ],
                    [
                        'heading' => 'Operational workflow',
                        'paragraphs' => [
                            'Floor staff scan case and item barcodes at receipt. TracePharma resolves SGTINs, compares against pending EPCIS for that ship-from GLN, and flags missing serials, quantity mismatches, or unexpected lots before the receipt is accepted.',
                            'Supervisors resolve exceptions at the receiving desk with supplier accountability context—who was supposed to send EPCIS, when it arrived, and whether a resend is needed.',
                        ],
                    ],
                    [
                        'heading' => 'What to measure after cutover',
                        'paragraphs' => [
                            'Track exception rate by supplier GLN, median time from scan to EPCIS match, and receipts closed without manual spreadsheet reconciliation. After partner onboarding stabilizes, aim to drive serial exceptions down over time.',
                        ],
                    ],
                ],
                'related_routes' => [
                    ['name' => 'marketing.features.show', 'params' => ['feature' => 'receiving'], 'label' => 'EPCIS receiving feature'],
                    ['name' => 'marketing.solutions.wholesalers', 'label' => 'Wholesaler solution page'],
                    ['name' => 'marketing.guides.epcis-vs-asn', 'label' => 'EPCIS vs ASN guide'],
                    ['name' => 'marketing.glossary.show', 'params' => ['term' => 'dscsa-3t'], 'label' => 'DSCSA 3T glossary'],
                ],
            ],
            'dscsa-saleable-returns' => [
                'slug' => 'dscsa-saleable-returns',
                'title' => 'DSCSA saleable returns without spreadsheet chaos',
                'meta_description' => 'Operational checklist for saleable returns under DSCSA—verification, EPCIS reship events, and audit trails for wholesalers and pharmacies.',
                'summary' => 'Returns are where serial state, VRS, and outbound EPCIS must align—or you create compliance debt.',
                'published_at' => '2026-06-10',
                'sections' => [
                    [
                        'heading' => 'Returns are a serial-state problem',
                        'paragraphs' => [
                            'Saleable returns require knowing whether each returned GTIN+serial is still valid, who last held custody, and whether the product can re-enter commerce. Spreadsheets and email threads do not survive FDA-style inquiries.',
                            'Wholesalers need inbound return EPCIS, verification against the original ship event, and clean outbound ObjectEvents when product ships again. Pharmacies need VRS checks and quarantine paths when verification fails.',
                        ],
                    ],
                    [
                        'heading' => 'Wholesaler vs pharmacy paths',
                        'paragraphs' => [
                            'Wholesalers typically receive return EPCIS from customers, match to original outbound events, and regenerate ship documents when product is resold. Pharmacies verify at return intake and document suspect product workflows including FDA Form 3911 when required.',
                            'TracePharma keeps both paths in one tenant with role-appropriate screens—dock receiving for distributors, dispense-adjacent checks for pharmacies.',
                        ],
                    ],
                    [
                        'heading' => 'Audit questions to prepare for',
                        'paragraphs' => [
                            'Can you produce the chain of custody for a returned serial in under five minutes? Do you log verification outcomes with timestamps? Can compliance export return-related exceptions without IT ticket?',
                        ],
                    ],
                ],
                'related_routes' => [
                    ['name' => 'marketing.glossary.show', 'params' => ['term' => 'saleable-returns'], 'label' => 'Saleable returns glossary'],
                    ['name' => 'marketing.features.show', 'params' => ['feature' => 'verification'], 'label' => 'VRS verification'],
                    ['name' => 'marketing.solutions.wholesalers', 'label' => 'Wholesaler workflows'],
                    ['name' => 'marketing.solutions.pharmacies', 'label' => 'Pharmacy workflows'],
                ],
            ],
            'choosing-l4-dscsa-provider' => [
                'slug' => 'choosing-l4-dscsa-provider',
                'title' => 'How to choose an L4 DSCSA provider in 2026',
                'meta_description' => 'Evaluation framework for L4 DSCSA SaaS—EPCIS depth, operator UX, partner connectivity, exceptions, and honest fit vs network incumbents.',
                'summary' => 'Shortlist vendors by what your operators do daily, not by logo count on a network slide.',
                'published_at' => '2026-06-05',
                'sections' => [
                    [
                        'heading' => 'Start with your operating profile',
                        'paragraphs' => [
                            'Manufacturers care about outbound ACK health and L3 handoff. Wholesalers care about receive-to-ship throughput and supplier exception rates. Pharmacies care about VRS at dispense and PMS adjacency. One vendor rarely optimizes all three equally.',
                            'Document inbound transport mix (AS2, SFTP, HTTPS), average partners per month, and whether you need scan-first vs file-first receiving before you sit through generic demos.',
                        ],
                    ],
                    [
                        'heading' => 'Non-negotiable capability checks',
                        'paragraphs' => [
                            'Ask for live EPCIS event-store investigation (1.2 GA; confirm whether 2.0 JSON-LD is enabled)—not slide decks claiming a full CBV 2.0 hub. Request exception workflows with supplier accountability, not just ingest logs. Confirm VRS audit trails for dispensers. Validate that pricing includes the partner count you will actually onboard in year one.',
                        ],
                    ],
                    [
                        'heading' => 'Network vs direct connectivity',
                        'paragraphs' => [
                            'Exchange networks reduce onboarding friction at enterprise scale but add per-partner economics. Direct AS2/SFTP presets suit mid-market operators who want control and predictable TCO. Neither is universally better—match to your IT capacity and partner mix.',
                        ],
                    ],
                ],
                'related_routes' => [
                    ['name' => 'marketing.compare.checklist', 'label' => 'DSCSA provider checklist'],
                    ['name' => 'marketing.compare.index', 'label' => 'Compare providers hub'],
                    ['name' => 'marketing.pricing', 'label' => 'Pricing approach'],
                    ['name' => 'marketing.glossary.show', 'params' => ['term' => 'l4'], 'label' => 'What is L4?'],
                ],
            ],
            'epcis-exception-investigation-playbook' => [
                'slug' => 'epcis-exception-investigation-playbook',
                'title' => 'EPCIS exception investigation playbook',
                'meta_description' => 'Step-by-step playbook for investigating missing serials, quantity mismatches, and delayed EPCIS—built for compliance leads and receiving supervisors.',
                'summary' => 'Turn ingest errors into accountable supplier conversations with a repeatable investigation path.',
                'published_at' => '2026-06-01',
                'sections' => [
                    [
                        'heading' => 'Classify the exception first',
                        'paragraphs' => [
                            'Missing EPCIS, partial serial coverage, wrong ship-from GLN, and duplicate ObjectEvents have different root causes and different supplier conversations. Tag each exception type at intake so trends roll up by supplier and transport.',
                        ],
                    ],
                    [
                        'heading' => 'Investigation sequence',
                        'paragraphs' => [
                            'Confirm the physical receipt (scan log or WMS receipt). Locate inbound EPCIS by document time and partner endpoint. Compare expected vs received serial count. Check for delayed files in the last 24 hours. Escalate to supplier with document ID, GLN, and sample serials—not screenshots of internal logs.',
                            'TracePharma surfaces document lineage and resend status so investigators do not export to Excel for every ticket.',
                        ],
                    ],
                    [
                        'heading' => 'Close the loop',
                        'paragraphs' => [
                            'Document resolution: resend received, manual adjustment approved, or product quarantined. Compliance should be able to filter closed exceptions by month for audit packs without re-querying the warehouse team.',
                        ],
                    ],
                ],
                'related_routes' => [
                    ['name' => 'marketing.features.show', 'params' => ['feature' => 'exceptions'], 'label' => 'Exceptions feature'],
                    ['name' => 'marketing.glossary.show', 'params' => ['term' => 'epcis-2-0'], 'label' => 'EPCIS 2.0 glossary'],
                    ['name' => 'marketing.guides.epcis-vs-asn', 'label' => 'EPCIS vs ASN guide'],
                    ['name' => 'marketing.customers.show', 'params' => ['slug' => 'regional-wholesaler-receive-to-ship'], 'label' => 'Illustrative wholesaler pattern'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     summary: string,
     *     published_at: string,
     *     organization_type: string,
     *     sections: list<array{heading: string, paragraphs: list<string>}>,
     *     related_routes: list<array{name: string, params?: array<string, string>, label: string}>
     * }>
     */
    public static function caseStudies(): array
    {
        return [
            'regional-wholesaler-receive-to-ship' => [
                'slug' => 'regional-wholesaler-receive-to-ship',
                'title' => 'Regional wholesaler: receive-to-ship in one L4 tenant',
                'meta_description' => 'Illustrative deployment pattern for a mid-market drug wholesaler unifying inbound EPCIS, scan-first receiving, outbound ship, and ACK monitoring.',
                'summary' => 'Consolidate dock scans, partner EPCIS, and outbound events without a separate WMS bolt-on for traceability.',
                'published_at' => '2026-06-12',
                'organization_type' => 'Regional drug wholesaler',
                'sections' => [
                    [
                        'heading' => 'Starting point',
                        'paragraphs' => [
                            'A fictional mid-Atlantic wholesaler serving hundreds of independent pharmacies received EPCIS from major manufacturers via AS2 but relied on email and spreadsheets for secondary suppliers. Outbound EPCIS was generated nightly in batch, delaying customer ACK visibility.',
                        ],
                    ],
                    [
                        'heading' => 'Deployment pattern',
                        'paragraphs' => [
                            'Direct AS2 and SFTP presets per supplier GLN. Scan-first receiving at two DCs with supervisor exception queue. Real-time outbound ObjectEvents tied to WMS ship confirm. ACK dashboard segmented by customer GLN with aging alerts.',
                        ],
                    ],
                    [
                        'heading' => 'Illustrative outcomes',
                        'paragraphs' => [
                            'Teams targeting this pattern often report faster supplier escalation (document ID in the exception ticket), fewer end-of-day receipt variances, and compliance exports that no longer require warehouse shift leads to rebuild serial lists manually.',
                        ],
                    ],
                ],
                'related_routes' => [
                    ['name' => 'marketing.solutions.wholesalers', 'label' => 'Wholesaler solution'],
                    ['name' => 'marketing.blog.show', 'params' => ['slug' => 'scan-first-receiving-wholesalers'], 'label' => 'Scan-first receiving article'],
                    ['name' => 'marketing.integrations.wholesale.index', 'label' => 'Wholesaler integration presets'],
                    ['name' => 'marketing.features.show', 'params' => ['feature' => 'receiving'], 'label' => 'Receiving feature'],
                ],
            ],
            'independent-pharmacy-epcis-vrs' => [
                'slug' => 'independent-pharmacy-epcis-vrs',
                'title' => 'Independent pharmacy: EPCIS receiving with VRS at dispense',
                'meta_description' => 'Illustrative deployment pattern for an independent pharmacy connecting wholesaler EPCIS, PMS dispense checks, and VRS verification audit trails.',
                'summary' => 'Close the loop between what the wholesaler shipped and what the pharmacist verifies before fill.',
                'published_at' => '2026-06-08',
                'organization_type' => 'Independent pharmacy',
                'sections' => [
                    [
                        'heading' => 'Starting point',
                        'paragraphs' => [
                            'A fictional two-location independent pharmacy used a free DSCSA portal for basic checks but lacked EPCIS receiving history tied to inventory and had no structured path for failed VRS or FDA Form 3911 documentation.',
                        ],
                    ],
                    [
                        'heading' => 'Deployment pattern',
                        'paragraphs' => [
                            'Inbound EPCIS from primary wholesaler HTTPS preset. Receiving aligned to tote and invoice. POST /api/v1/dispense-check for verification before fill (named PMS adapters not GA). Quarantine workflow with 3911 export template for compliance lead review.',
                        ],
                    ],
                    [
                        'heading' => 'Illustrative outcomes',
                        'paragraphs' => [
                            'Pharmacies adopting this pattern aim for verification logs that satisfy board inspection questions, fewer manual lookups when a serial fails, and clear escalation when wholesaler EPCIS arrives after physical receipt.',
                        ],
                    ],
                ],
                'related_routes' => [
                    ['name' => 'marketing.solutions.pharmacies', 'label' => 'Pharmacy solution'],
                    ['name' => 'marketing.integrations.pms.index', 'label' => 'PMS integrations'],
                    ['name' => 'marketing.features.show', 'params' => ['feature' => 'verification'], 'label' => 'Verification feature'],
                    ['name' => 'marketing.glossary.show', 'params' => ['term' => 'vrs'], 'label' => 'VRS glossary'],
                ],
            ],
            'manufacturer-outbound-ack-health' => [
                'slug' => 'manufacturer-outbound-ack-health',
                'title' => 'Manufacturer: outbound EPCIS and customer ACK health',
                'meta_description' => 'Illustrative deployment pattern for a specialty manufacturer monitoring outbound EPCIS delivery and trading-partner ACK status from one L4 hub.',
                'summary' => 'See which customers accepted your ship events—and which connections need IT attention before audits.',
                'published_at' => '2026-06-03',
                'organization_type' => 'Specialty drug manufacturer',
                'sections' => [
                    [
                        'heading' => 'Starting point',
                        'paragraphs' => [
                            'A fictional specialty manufacturer shipped to a mix of Big-3 wholesalers and regional distributors. Outbound EPCIS left the corporate hub but ACK monitoring lived in separate spreadsheets per customer success manager.',
                        ],
                    ],
                    [
                        'heading' => 'Deployment pattern',
                        'paragraphs' => [
                            'L3 commissioning forward (Organization settings URL + idempotent POST) to plant or corporate L3. Outbound ObjectEvents at ship confirm from ERP/WMS integration. Per-customer ACK dashboard with transport status. Exception queue for rejected or missing ACKs with resend workflow.',
                        ],
                    ],
                    [
                        'heading' => 'Illustrative outcomes',
                        'paragraphs' => [
                            'Manufacturers targeting this pattern typically want customer success and compliance aligned on the same ACK metrics, faster identification of partner endpoint drift, and audit-ready export of outbound document lineage by lot and ship date.',
                        ],
                    ],
                ],
                'related_routes' => [
                    ['name' => 'marketing.solutions.manufacturers', 'label' => 'Manufacturer solution'],
                    ['name' => 'marketing.features.show', 'params' => ['feature' => 'serialization'], 'label' => 'Serialization & L3 handoff'],
                    ['name' => 'marketing.integrations.erp.index', 'label' => 'ERP adjacency'],
                    ['name' => 'marketing.compare.tracelink', 'label' => 'TraceLink alternative'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     summary: string,
     *     published_at: string,
     *     sections: list<array{heading: string, paragraphs: list<string>}>,
     *     related_routes: list<array{name: string, params?: array<string, string>, label: string}>
     * }>
     */
    public static function allBlogPosts(): array
    {
        return array_values(self::blogPosts());
    }

    /**
     * @return list<array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     summary: string,
     *     published_at: string,
     *     organization_type: string,
     *     sections: list<array{heading: string, paragraphs: list<string>}>,
     *     related_routes: list<array{name: string, params?: array<string, string>, label: string}>
     * }>
     */
    public static function allCaseStudies(): array
    {
        return array_values(self::caseStudies());
    }

    /**
     * @return array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     summary: string,
     *     published_at: string,
     *     sections: list<array{heading: string, paragraphs: list<string>}>,
     *     related_routes: list<array{name: string, params?: array<string, string>, label: string}>
     * }
     */
    public static function getBlogPost(string $slug): array
    {
        return self::blogPosts()[$slug];
    }

    /**
     * @return array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     summary: string,
     *     published_at: string,
     *     organization_type: string,
     *     sections: list<array{heading: string, paragraphs: list<string>}>,
     *     related_routes: list<array{name: string, params?: array<string, string>, label: string}>
     * }
     */
    public static function getCaseStudy(string $slug): array
    {
        return self::caseStudies()[$slug];
    }
}
