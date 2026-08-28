@extends('marketing.layout')

@section('title', '3PL & logistics — TracePharma')
@section('meta_description', 'Level 4 DSCSA for 3PL and contract logistics: principal-scoped receiving and shipping, cross-dock transfers, lot-level outbound, and multi-facility operations.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · 3PL & logistics"
        title="Operate on behalf of principals — one L4 hub per warehouse network"
        description="Before TracePharma, contract warehouses often commingle principal inventory in generic WMS views—leaving brand owners asking for serial proof the 3PL cannot produce quickly. TracePharma is the corporate traceability workspace for contract logistics: receive manufacturer EPCIS per principal, ship lot-level and serialized outbound, cross-dock between facilities, and report principal-scoped ACK health without a global exchange middleman."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / 3PL &amp; logistics
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a 3PL demo →</a>
            <a href="{{ route('marketing.features.show', 'integrations') }}">Partner connectivity →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.pipeline-steps
            :steps="[
                ['phase' => 'Onboard', 'title' => 'Principal registry', 'description' => 'Register brand owners whose inventory you warehouse and ship on behalf of.'],
                ['phase' => 'Receive', 'title' => 'Inbound EPCIS', 'description' => 'Principal-scoped receiving with scan-confirm and expected-shipment matching.'],
                ['phase' => 'Transfer', 'title' => 'Cross-dock', 'description' => 'Facility-to-facility transfers with immutable audit trail.'],
                ['phase' => 'Ship', 'title' => 'Lot & serial outbound', 'description' => 'Mixed lot-level and serialized ship orders with 3T documents.'],
                ['phase' => 'Monitor', 'title' => 'Principal scorecard', 'description' => 'Supplier, customer, and principal partner health in one operations view.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Principal operations', 'description' => 'Filter dashboards, scorecards, and outbound by principal—so each brand owner sees only their inventory and messages.'],
                    ['title' => 'Cross-dock transfer', 'description' => 'Move inventory between GLNs with staged scan verification—so facility transfers stay auditable without commingled serial history.'],
                    ['title' => 'Lot-level shipping', 'description' => 'Ship non-serialized lines by GTIN + lot + quantity alongside serialized units—so mixed orders ship on one outbound EPCIS drop.'],
                    ['title' => 'SSCC labeling', 'description' => 'Generate pallet labels with pool low-water alerts—so warehouse staff label outbound pallets before serial pools run dry.'],
                    ['title' => 'Cross-dock audit trail', 'description' => 'Immutable activity log for queued transfers—so principal account managers answer who moved what between which facilities.'],
                    ['title' => 'WMS ship-confirm bridge', 'description' => 'Manhattan/Körber callbacks → outbound EPCIS with operations scorecard trends—so WMS confirmations become trace events without manual file builds.', 'href' => route('marketing.features.show', 'integrations')],
                    ['title' => 'EPCIS receiving', 'description' => 'SFTP, AS2, HTTPS webhooks, and manual upload from manufacturer principals—so inbound files route to the right principal scope on arrival.', 'href' => route('marketing.features.show', 'receiving')],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'Principal isolation', 'description' => 'Each principal\'s inventory, outbound messages, and exceptions stay scoped — no commingled reporting.', 'items' => ['Principal onboarding wizard step', 'Principal filter on operations scorecard', '3PL principal field on ship orders']],
                ['title' => 'Multi-facility operations', 'description' => 'Ship-from facility selection, cross-dock between DCs, and read-point gate enforcement.', 'items' => ['Facility-scoped outbound staging', 'Cross-dock scan verification', 'Integration health per connection']],
                ['title' => 'DSCSA distributor obligations', 'description' => 'Receive serialized product, verify transaction data, and ship with attached TI/TH/TS.', 'items' => ['EPCIS 1.2 GA + 2.0 capture/query/subscriptions', 'Transaction search at scale', 'Compliance export API']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See 3PL workflows live"
        description="Request a 3PL demo—we'll walk through principal onboarding, inbound manufacturer EPCIS, cross-dock transfer, lot-level ship to customer, and principal-scoped scorecard on a demo tenant."
    />
@endsection