@extends('marketing.layout')

@section('title', 'Features — TracePharma')
@section('meta_description', 'L4 DSCSA features: EPCIS receiving and shipping, L3 commissioning forward, exceptions, partner connectivity, VRS verification, and compliance reporting.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Platform features"
        title="One L4 workspace — seven operating profiles"
        description="TracePharma is multi-tenant DSCSA SaaS. Each customer gets an isolated database, subdomain app access, and profile-tuned workflows—from manufacturer outbound to wholesaler receive-to-ship, 3PL soft principal tags, and dispenser verification."
    >
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold text-tp-ink">Follow the DSCSA workflow</h2>
            <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                Deep-dive guides map to how product moves through your network—from inbound EPCIS and L3 commissioning forward through ship, verify (where applicable), exceptions, and compliance reporting.
            </p>
        </div>

        <x-marketing.capability-map
            class="mt-8"
            :links="[
                [
                    'phase' => 'Operate',
                    'title' => 'Operations Hub',
                    'description' => 'Scan-first router to receive, verify, ship, or trace—so dock and floor staff start every task from one barcode.',
                    'href' => route('marketing.features.show', 'receiving'),
                ],
                [
                    'phase' => 'Receive',
                    'title' => 'EPCIS receiving',
                    'description' => 'Inbound files, 3T intake, scan-first matching—so missing serials surface before product hits inventory.',
                    'href' => route('marketing.features.show', 'receiving'),
                ],
                [
                    'phase' => 'Ship',
                    'title' => 'Outbound EPCIS',
                    'description' => 'Consolidated shipments, SSCC labels, partner ACK—so customers receive traceable outbound EPCIS on every ship.',
                    'href' => route('marketing.features.show', 'integrations'),
                ],
                [
                    'phase' => 'Serialize',
                    'title' => 'L3 ↔ L4 handoff',
                    'description' => 'Organization L3 forward URL plus idempotent commissioning POST—so plant systems stay in place without a vapor allocation API.',
                    'href' => route('marketing.features.show', 'serialization'),
                ],
                [
                    'phase' => 'Verify',
                    'title' => 'VRS & dispense',
                    'description' => 'Dispenser profiles: workstation and POST /api/v1/dispense-check—so verify outcomes land in an audit log auditors can review.',
                    'href' => route('marketing.features.show', 'verification'),
                ],
                [
                    'phase' => 'Returns',
                    'title' => 'Saleable returns',
                    'description' => 'Outbound return EPCIS and reverse logistics scorecard—so wholesalers and manufacturers document saleable return custody.',
                    'href' => route('marketing.blog.show', 'dscsa-saleable-returns'),
                ],
                [
                    'phase' => 'Resolve',
                    'title' => 'Exceptions',
                    'description' => 'Reason codes, supplier corrections, risk scoring—so failures route to resolution instead of inbox limbo.',
                    'href' => route('marketing.features.show', 'exceptions'),
                ],
                [
                    'phase' => 'Report',
                    'title' => 'Compliance',
                    'description' => 'FDA 3911, verification summaries, license checks—so inspection prep pulls from live operational data.',
                    'href' => route('marketing.features.show', 'compliance'),
                ],
                [
                    'phase' => 'Connect',
                    'title' => 'Integrations',
                    'description' => 'API, webhooks, AS2, SFTP, WMS bridges—so partner automation connects without a separate integration project.',
                    'href' => route('marketing.features.show', 'integrations'),
                ],
            ]"
        />

        <p class="mt-6 text-sm text-tp-muted">
            New to inbound file formats?
            <a href="{{ route('marketing.guides.epcis-vs-asn') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Read the EPCIS vs ASN guide →</a>
        </p>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,16rem)_1fr] lg:items-start">
                <div>
                    <h2 class="text-xl font-semibold text-tp-ink">Daily operations</h2>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                        Capabilities floor staff, compliance leads, and integration teams touch every shift—not roadmap items.
                    </p>
                </div>
                <x-marketing.feature-list
                    :items="[
                        ['title' => 'EPCIS receiving & shipping', 'description' => 'Inbound via upload, SFTP, AS2, or webhooks. Outbound EPCIS with SSCC labeling and partner routing—so receive and ship stay in one L4 workspace.'],
                        ['title' => 'L3 commissioning forward', 'description' => 'Organization settings L3 URL plus ForwardCommissioningToL3—so manufacturer and prepackager lines stay connected without a public allocation API.'],
                        ['title' => 'Exception management', 'description' => 'Structured reason codes, assignment, resolution notes, and playbook guidance—so operators know the next step on every failure.'],
                        ['title' => 'Supplier correction loop', 'description' => 'Send correction requests to trading partners and optionally auto-send on new exceptions—so supplier accountability is tracked in-system, not in email.'],
                        ['title' => 'VRS verification', 'description' => 'Workstation and API checks with full audit log—so pharmacy, wholesaler, and dental/medical profiles prove verify outcomes at inspection.'],
                        ['title' => 'Partner risk scoring', 'description' => 'Failure-rate trends per trading partner—so compliance leads prioritize wholesaler conversations before exceptions spike.'],
                        ['title' => 'Operations Hub', 'description' => 'Scan a barcode and route to receive, verify, ship, or trace—so floor staff start from one entry point instead of hunting menus.'],
                        ['title' => 'Strict EPCIS profile enforcement', 'description' => 'Reject non-conforming inbound files and hold shipments for compliance review—so bad partner data never silently lands in inventory.'],
                        ['title' => 'Ship-from-received inventory', 'description' => 'Ship outbound from inbound delivery inventory without a separate putaway step—so cross-dock and fast-turn DC lanes stay in one L4 workspace.'],
                        ['title' => 'Receiving acceptance metrics', 'description' => 'Dashboard for receipt pass rates, validation failures, and SLA trends—so receiving managers see dock health before exceptions spike.'],
                        ['title' => 'Saleable return outbound', 'description' => 'Reverse logistics scorecard and outbound return EPCIS—so manufacturer and wholesaler profiles document saleable return custody (profile-gated).'],
                        ['title' => 'Compliance case management', 'description' => 'Tracing requests, recall management, and investigation cases—so compliance leads close regulatory workflows with linked event evidence.'],
                    ]"
                />
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold text-tp-ink">Receiving through outbound ship</h2>
            <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                One continuous L4 chain—from manufacturer drop or wholesaler receipt to labeled pallet—without swapping tools mid-shift.
            </p>
        </div>

        <x-marketing.pipeline-steps
            class="mt-8"
            :steps="[
                ['phase' => 'Ingest', 'title' => 'EPCIS ingestion', 'description' => 'Upload, SFTP poll, AS2, and inbound webhooks—so partner files arrive on the schedule they already use.'],
                ['phase' => 'Match', 'title' => 'Shipment receipt', 'description' => 'Scan-first or file-first matching with operator-friendly status—so receiving clerks see pass/fail before closing a receipt.'],
                ['phase' => 'Intake', 'title' => '3T documents', 'description' => 'TI, TH, and TS handling with structured exceptions—so missing transaction data becomes a tracked issue, not a silent gap.'],
                ['phase' => 'Trace', 'title' => 'Product trace', 'description' => 'Serial history across inbound and outbound events—so investigations start from event data, not reconstructed spreadsheets.'],
                ['phase' => 'Ship', 'title' => 'Outbound EPCIS', 'description' => 'Consolidated shipments, partner routing, SSCC labels—so downstream customers receive compliant transaction statements.'],
            ]"
        />
    </section>

    <section class="border-t border-tp-border bg-tp-surface/30">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="grid gap-8 lg:grid-cols-2">
                <x-marketing.detail-section
                    title="Reporting for audits"
                    :items="[
                        'FDA Form 3911 prefilled from verification exceptions with export support.',
                        'Period-based verification summary statistics for management review.',
                        'Operations and dispenser scorecards with blocked-reason trends.',
                    ]"
                />
                <x-marketing.detail-section
                    title="Outbound integrations"
                    :items="[
                        'Configurable HTTPS webhooks for verification outcomes and exceptions.',
                        'WMS ship-confirm bridge for Manhattan and Körber outbound EPCIS.',
                        'REST API for verification, receiving status, and exception queries.',
                    ]"
                />
            </div>
        </div>
    </section>

    <section class="border-t border-tp-border">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="max-w-2xl">
                <h2 class="text-xl font-semibold text-tp-ink">Solutions by industry</h2>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                    Feature guides above map to workflows. Industry pages tailor the story to your supply-chain role.
                </p>
            </div>
            <x-marketing.industry-picker
                class="mt-8"
                :industries="[
                    [
                        'label' => 'Manufacturer',
                        'title' => 'Drug manufacturers',
                        'description' => 'Outbound EPCIS, L3 commissioning forward, ACK monitoring, and receive saleable returns—so brand owners ship with traceable transaction data and close the return loop.',
                        'href' => route('marketing.solutions.manufacturers'),
                        'highlight' => true,
                    ],
                    [
                        'label' => 'Distributor',
                        'title' => 'Drug wholesalers',
                        'description' => 'Full L4 receive-to-ship, exceptions, ACK monitoring, and partner connectivity—so regional DC teams run one operator workspace.',
                        'href' => route('marketing.solutions.wholesalers'),
                        'highlight' => true,
                    ],
                    [
                        'label' => '3PL',
                        'title' => 'Logistics & 3PL',
                        'description' => 'Principal-scoped receiving, cross-dock, and lot-level ship—so 3PL staff keep each brand owner\'s inventory isolated.',
                        'href' => route('marketing.solutions.3pl'),
                        'highlight' => true,
                    ],
                    [
                        'label' => 'Dispenser',
                        'title' => 'Pharmacies',
                        'description' => 'EPCIS receiving, VRS verify, FDA 3911, and wholesaler SFTP presets—so independents close the loop from receipt to dispense.',
                        'href' => route('marketing.solutions.pharmacies'),
                    ],
                    [
                        'label' => 'Repack',
                        'title' => 'Prepackagers',
                        'description' => 'Bulk receive, repack lineage, and outbound EPCIS with new serials—so contract packagers defend multi-hop lineage.',
                        'href' => route('marketing.solutions.prepackagers'),
                    ],
                    [
                        'label' => 'Network',
                        'title' => 'Buying groups',
                        'description' => 'Member health, exception trends, and partner authorization—so group admins see network compliance without per-store exports.',
                        'href' => route('marketing.solutions.buying-groups'),
                    ],
                    [
                        'label' => 'Supply',
                        'title' => 'Dental & medical',
                        'description' => 'Mixed Rx/non-Rx catalog with practice ship-to GLNs—so specialty distributors run DSCSA without a pharma-only portal.',
                        'href' => route('marketing.solutions.dental-medical'),
                    ],
                ]"
            />
        </div>
    </section>

    <x-marketing.cta-banner
        title="Want to see these workflows live?"
        description="Request a demo—we'll map features to your operating profile: manufacturer outbound, wholesaler receive-to-ship, 3PL principals, or dispenser verification."
    />
@endsection
