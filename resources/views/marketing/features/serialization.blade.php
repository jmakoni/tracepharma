@extends('marketing.layout')

@section('title', 'Serialization & L3 provisioning — TracePharma features')
@section('meta_description', 'Level 4 serial authority with Level 3 plant-floor handoff: SGTIN pools, allocation export or API, commissioning reconciliation, and EPCIS-native traceability.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Feature deep dive"
        title="Serialization — L4 authority with L3 plant-floor handoff"
        description="TracePharma holds your corporate SGTIN serial pools and provisions ranges to packaging-line systems. Commissioning events flow back for reconciliation—via standard file export or REST API, compatible with the plant-floor serialization software you already operate."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.features') }}">Features</a> / Serialization
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.solutions.manufacturers') }}">Manufacturer solution →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div>
                <div class="max-w-3xl">
                    <h2 class="text-xl font-semibold text-tp-ink">How L3 and L4 work together</h2>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                        Level 3 systems commission and aggregate at the line. Level 4 is the corporate hub—serial authority, partner EPCIS, and compliance workflows. TracePharma bridges both without forcing you to replace your plant-floor stack or operate national regulatory hubs (L5).
                    </p>
                </div>

                <x-marketing.pipeline-steps
                    class="mt-8"
                    :steps="[
                        ['phase' => 'Pool', 'title' => 'SGTIN serial pools', 'description' => 'Define GTIN-level pools with range boundaries and low-water visibility.'],
                        ['phase' => 'Reserve', 'title' => 'Create allocation', 'description' => 'Reserve a serial sub-range for a packaging line or contract packager run.'],
                        ['phase' => 'Deliver', 'title' => 'Provision to L3', 'description' => 'Download a structured export or POST via API adapter to your line system.'],
                        ['phase' => 'Commission', 'title' => 'Line events return', 'description' => 'Commissioning EPCIS arrives inbound and tags against the allocation.'],
                        ['phase' => 'Close', 'title' => 'Reconcile gaps', 'description' => 'Scorecard surfaces open allocations and commissioning mismatches.'],
                    ]"
                />
            </div>
            <x-marketing.l4-stack />
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="grid gap-6 lg:grid-cols-2">
                <x-marketing.detail-section
                    title="Serial pool management"
                    :items="[
                        'GTIN-scoped SGTIN pools with configurable range boundaries.',
                        'Allocator reserves non-overlapping sub-ranges per production run.',
                        'Open allocation tracking on manufacturer operations scorecard.',
                    ]"
                />
                <x-marketing.detail-section
                    title="Provision methods"
                    :items="[
                        'Structured file export for line-system import workflows.',
                        'REST API adapter for automated handoff to plant-floor software.',
                        'Per-system credentials and inbound connection linking.',
                    ]"
                />
                <x-marketing.detail-section
                    title="Commissioning reconciliation"
                    :items="[
                        'Inbound commissioning EPCIS auto-tagged to active allocations.',
                        'Gap detection when commissioned serials fall outside reserved ranges.',
                        'Commissioning reconciliation after each processed inbound file.',
                    ]"
                />
                <x-marketing.detail-section
                    title="Works with your line systems"
                    :items="[
                        'Designed for standard plant-floor serialization platforms—not a single-vendor lock-in.',
                        'Link allocations to plant floor connectors and packaging line GLNs.',
                        'Manufacturer and prepackager tenant profiles gate this capability.',
                    ]"
                />
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold text-tp-ink">Beyond provisioning</h2>
            <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                Serialization in TracePharma is EPCIS-native end to end—not a standalone UID module disconnected from ship and trace.
            </p>
        </div>

        <x-marketing.module-grid
            class="mt-8"
            :modules="[
                [
                    'title' => 'Outbound EPCIS generation',
                    'description' => 'Ship commissioning and aggregation events to wholesaler customers with full transaction statements—so outbound drops carry TI/TH/TS your customers need for receiving.',
                    'href' => route('marketing.features.show', 'integrations'),
                ],
                [
                    'title' => 'Product trace',
                    'description' => 'Serial-level history across commission, ship, return, and repack lineage chains—so investigations start from event data, not email threads.',
                ],
                [
                    'title' => 'Decommission & scrap',
                    'description' => 'Destroy and sample workflows with EPCIS events—so QA audits include scrap and sample pulls in the compliance package.',
                ],
                [
                    'title' => 'EPCIS 2.0 repository',
                    'description' => 'CBV 2.0 capture, queries, and subscriptions for manufacturer, wholesaler, 3PL, and prepackager profiles—pharmacy tenants get a read-only repository for IT trace queries.',
                    'href' => route('marketing.features.show', 'integrations'),
                ],
                [
                    'title' => 'Plant floor telemetry',
                    'description' => 'Line heartbeat API ties packaging throughput to operations scorecards—so serialization IT sees line health alongside outbound volume.',
                ],
                [
                    'title' => 'Prepack / repack lineage',
                    'description' => 'Parent-child serial relationships across multi-hop repack chains—so contract packagers defend lineage across more than one repack hop.',
                ],
            ]"
        />
    </section>

    <section class="border-t border-tp-border bg-tp-surface/30">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="max-w-2xl">
                <h2 class="text-xl font-semibold text-tp-ink">L3 provisioning by line-system type</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                    TracePharma provisions the L4 hub — your plant-floor line software stays in place. Demo and staging commands vary by provider; all paths allocate SGTIN ranges, link commissioning inbound, and reconcile EPCIS.
                </p>
            </div>
            <div class="mt-8 grid gap-4 md:grid-cols-3">
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Plant-floor line</p>
                    <p class="mt-2 font-semibold text-tp-ink">Line serialization platform</p>
                    <p class="mt-2 font-mono text-xs text-tp-muted">tracepharma:provision-l3-systech</p>
                    <p class="mt-2 text-sm text-tp-muted">CSV export + commissioning inbound for standard packaging lines.</p>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">SAP ATTP</p>
                    <p class="mt-2 font-semibold text-tp-ink">Corporate ERP serialization</p>
                    <p class="mt-2 font-mono text-xs text-tp-muted">tracepharma:provision-l3-sap-attp</p>
                    <p class="mt-2 text-sm text-tp-muted">REST adapter + serial request webhook for SAP Advanced Track and Trace.</p>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">TraceLink SNM</p>
                    <p class="mt-2 font-semibold text-tp-ink">Network serial manager</p>
                    <p class="mt-2 font-mono text-xs text-tp-muted">tracepharma:provision-l3-tracelink-snm</p>
                    <p class="mt-2 text-sm text-tp-muted">Serial number manager handoff for TraceLink-integrated plants.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="border-t border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">API surface (manufacturer tenants)</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                    Integration teams can automate allocation lifecycle without the Filament UI.
                </p>
                <ul class="mt-6 space-y-3 font-mono text-sm text-tp-muted">
                    <li><span class="text-tp-teal-400">GET</span> /api/v1/l3/allocations</li>
                    <li><span class="text-tp-teal-400">POST</span> /api/v1/l3/allocations</li>
                    <li><span class="text-tp-teal-400">GET</span> /api/v1/l3/allocations/{id}/export</li>
                    <li><span class="text-tp-teal-400">POST</span> /api/v1/plant-floor/telemetry</li>
                </ul>
                <p class="mt-6 text-sm text-tp-muted">
                    Sanctum-authenticated, tenant-scoped. See operations documentation for payload schemas.
                </p>
            </div>
        </div>
    </section>

    @include('marketing.partials.feature-related', ['current' => 'serialization'])
    <x-marketing.cta-banner
        title="Map your L3 ↔ L4 cutover"
        description="Request a demo—we'll align serial pools, provision method, and inbound commissioning path to your packaging lines and wholesaler customers."
    />
@endsection