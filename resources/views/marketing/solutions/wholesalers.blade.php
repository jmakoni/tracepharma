@extends('marketing.layout')

@section('title', 'Drug wholesalers — TracePharma')
@section('meta_description', 'Level 4 DSCSA for regional drug wholesalers: EPCIS receive-to-ship, 3T matching, outbound ACK monitoring, exceptions, and direct partner connectivity.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Drug wholesalers"
        title="Receive, match, ship — one L4 workspace for your DC"
        description="Before TracePharma, regional DC teams often split receiving in one tool, exceptions in email, and outbound EPCIS in consultant-maintained scripts. TracePharma is the corporate traceability hub for regional distributors: ingest manufacturer EPCIS, match transaction data, ship pharmacies with TI/TH/TS, and monitor customer ACK health without a global exchange middleman."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / Drug wholesalers
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a wholesaler demo →</a>
            <a href="{{ route('marketing.features.show', 'receiving') }}">EPCIS receiving →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.pipeline-steps
            :steps="[
                ['phase' => 'Ingest', 'title' => 'Inbound EPCIS', 'description' => 'SFTP, AS2, HTTPS webhooks, and manual upload from manufacturers.'],
                ['phase' => 'Match', 'title' => '3T & receipt', 'description' => 'Operator receiving with scan-confirm and expected-shipment matching.'],
                ['phase' => 'Resolve', 'title' => 'Exceptions', 'description' => 'Structured reason codes, supplier correction loop, SLA tracking.'],
                ['phase' => 'Ship', 'title' => 'Outbound EPCIS', 'description' => 'Ship order with transaction statements and SSCC labeling.'],
                ['phase' => 'Monitor', 'title' => 'ACK health', 'description' => 'Stale ACK alerts, WMS ship-confirm audit, and operations scorecard per customer.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'EPCIS receiving', 'description' => 'Scan-first and file-first receiving with mobile-friendly workstation UX—so receiving clerks close receipts before product hits sellable inventory.', 'href' => route('marketing.features.show', 'receiving')],
                    ['title' => 'Exception investigation', 'description' => 'Inbound/outbound monitors, partner risk scoring, correction SLA banners—so compliance leads see supplier issues before they become shipment holds.', 'href' => route('marketing.features.show', 'exceptions')],
                    ['title' => 'Outbound shipping', 'description' => 'Ship order, break-pallet, cross-dock, pack/unpack, and consolidated outbound EPCIS—so pharmacy customers get TI/TH/TS on every outbound drop.', 'href' => route('marketing.features.show', 'integrations')],
                    ['title' => 'Ship-from-received', 'description' => 'Ship outbound from inbound delivery inventory without a separate putaway step—so cross-dock and fast-turn lanes stay in one L4 workspace.'],
                    ['title' => 'Saleable return outbound', 'description' => 'Outbound return EPCIS with custody documentation—so regional DCs close the saleable return loop without a separate returns portal.'],
                    ['title' => 'Reverse logistics scorecard', 'description' => 'Return volume, verification outcomes, and partner trends—so compliance leads see reverse logistics health alongside forward ship metrics.'],
                    ['title' => 'ACK monitoring', 'description' => 'Outbound message monitor with alerts when pharmacy customers stop acknowledging—so customer success calls happen before stale ACKs pile up.'],
                    ['title' => 'ATP & licenses', 'description' => 'Trading partner license validation with daily revalidation—so you do not ship to partners with lapsed authorization.'],
                    ['title' => 'WMS ship-confirm bridge', 'description' => 'Manhattan/Körber webhook → outbound EPCIS with blocked-reason trends—so WMS ship events become traceable EPCIS without manual re-entry.', 'href' => route('marketing.features.show', 'integrations')],
                    ['title' => 'Compliance export', 'description' => 'JSON/CSV audit export API—so management and inspection prep pull evidence from live ops, not ad hoc spreadsheets.'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'DSCSA distributor obligations', 'description' => 'Receive serialized product, verify transaction data, and ship with attached TI/TH/TS.', 'items' => ['EPCIS 1.2 GA + 2.0 capture/query/subscriptions', 'Transaction search at scale', 'Tracing request SLA tracking']],
                ['title' => 'Partner connectivity', 'description' => 'Connect directly to manufacturers and pharmacies — presets for major serialization platforms.', 'items' => ['TraceLink, UniTrace, LSPedia presets', 'Gateway Checker and regional SFTP', 'No mandatory network enrollment fees']],
                ['title' => 'Operator-first UX', 'description' => 'Built for receiving clerks and compliance officers — not consultant-only configuration.', 'items' => ['Persona-based navigation', 'Scan-to-ship on outbound pages', 'Integration health with connection test actions']],
            ]"
        />
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="tp-card p-8">
            <h2 class="text-lg font-semibold text-tp-ink">Evaluating enterprise L4 suites?</h2>
            <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                LSPedia OneScan and TraceLink Opus serve global programs and network-scale exchange well. TracePharma fits regional wholesalers who need receive-to-ship L4, direct partner connectivity, and operator UX—without network enrollment fees.
            </p>
            <a href="{{ route('marketing.compare.lspedia') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">LSPedia alternative comparison →</a>
        </div>
    </section>

    <x-marketing.cta-banner
        title="See wholesaler workflows live"
        description="Request a wholesaler demo—we'll walk through inbound manufacturer EPCIS, 3T match, outbound ship to pharmacy, and ACK monitoring on a demo tenant."
    />
@endsection