@extends('marketing.layout')

@section('title', 'Serialization & L3 handoff — TracePharma features')
@section('meta_description', 'Level 4 serial authority with Level 3 plant-floor handoff: SSCC labeling, commissioning forward to your L3 endpoint, and EPCIS-native traceability.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Feature deep dive"
        title="Serialization — L4 authority with L3 plant-floor handoff"
        description="TracePharma authors commissioning and shipping EPCIS as the corporate L4 hub. When you configure an L3 endpoint in organization settings, authored commissioning documents can be forwarded to your plant-floor or enterprise serialization system—without inventing a separate allocation API surface."
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
                        ['phase' => 'Label', 'title' => 'Author SSCCs / commissions', 'description' => 'Create SSCC ranges and commissioning EPCIS in TracePharma (L4).'],
                        ['phase' => 'Forward', 'title' => 'Hand off to L3', 'description' => 'Optional: POST commissioning XML to your configured L3 HTTPS endpoint.'],
                        ['phase' => 'Exchange', 'title' => 'Partner EPCIS', 'description' => 'Ship and receive EPCIS 1.2 with wholesalers and pharmacies.'],
                        ['phase' => 'Trace', 'title' => 'Investigate', 'description' => 'Serial-level history across commission, ship, return, and repack.'],
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
                    title="L4 commissioning & labeling"
                    :items="[
                        'SSCC number ranges and authored commissioning EPCIS in TracePharma.',
                        'Ship Orders generate EPCIS 1.2 XML with TI/TS for trading partners.',
                        'Tenant profiles gate labeling and outbound for manufacturers and prepackagers.',
                    ]"
                />
                <x-marketing.detail-section
                    title="L3 forward (optional)"
                    :items="[
                        'Configure an L3 HTTPS endpoint in Organization settings.',
                        'Authored commissioning documents can be POSTed to that endpoint after generation.',
                        'Idempotent forward markers prevent duplicate handoffs.',
                    ]"
                />
                <x-marketing.detail-section
                    title="Inbound commissioning"
                    :items="[
                        'Receive commissioning EPCIS from partners or plant systems into the tenant ledger.',
                        'Reconcile custody and exceptions in the same compliance workflows as shipping.',
                        'No separate vapor allocation API—events and settings drive the handoff.',
                    ]"
                />
                <x-marketing.detail-section
                    title="Works with your line systems"
                    :items="[
                        'Designed for standard plant-floor serialization platforms—not a single-vendor lock-in.',
                        'Keep your L3 stack; TracePharma remains the L4 hub for partners and compliance.',
                        'Guardian / Systech lot-feed ingest remains a separate planned path when needed.',
                    ]"
                />
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-2xl">
                <h2 class="text-xl font-semibold text-tp-ink">Beyond L3 handoff</h2>
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
                    'title' => 'EPCIS 1.2 GA + 2.0 capture & query',
                    'description' => 'EPCIS 1.2 XML is the default outbound; Ship Orders always author 1.2 XML today. JSON-LD 2.0 is opt-in per connection for disposition and other resolver-backed documents when enabled. Capture JSON-LD and XML 2.0 when accept_20 is on. Sanctum offers query-as-2.0, GS1-shaped Capture + SimpleEventQuery, and GS1 subscribe/unsubscribe with HMAC callbacks—an honest dual-stack subset, not a certified GS1 Exchange hub.',
                    'href' => route('marketing.features.show', 'integrations'),
                ],
                [
                    'title' => 'Operations scorecards',
                    'description' => 'Outbound volume, ACK health, and commissioning forward status on operations scorecards—so serialization IT sees partner and handoff health without inventing a line-heartbeat API.',
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
                <h2 class="text-xl font-semibold text-tp-ink">L3 handoff today</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                    TracePharma remains the L4 hub. Plant-floor L3 systems stay in place. Configure an L3 forward URL and credentials in Organization settings; when commissioning EPCIS is authored, TracePharma can POST it to that endpoint (idempotent). Dedicated allocation provision commands and a public `/api/v1/l3/allocations` surface are not shipped—use settings + commissioning forward, or talk to us about a custom plant cutover.
                </p>
            </div>
            <div class="mt-8 grid gap-4 md:grid-cols-3">
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Settings</p>
                    <p class="mt-2 font-semibold text-tp-ink">L3 endpoint</p>
                    <p class="mt-2 text-sm text-tp-muted">HTTPS URL and auth in Organization settings for commissioning forward.</p>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Runtime</p>
                    <p class="mt-2 font-semibold text-tp-ink">Forward commissioning</p>
                    <p class="mt-2 text-sm text-tp-muted">Queue job posts authored commissioning XML and stamps forwarded-at for idempotency.</p>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Partners</p>
                    <p class="mt-2 font-semibold text-tp-ink">EPCIS 1.2 ship</p>
                    <p class="mt-2 text-sm text-tp-muted">Ship Orders author 1.2 XML for wholesaler and pharmacy exchange regardless of connection 2.0 pins.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="border-t border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Integration surfaces (honest)</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                    Use Sanctum APIs that exist today—EPCIS capture/query, ship confirm, and dispense-check—rather than a fictional L3 allocation CRUD.
                </p>
                <ul class="mt-6 space-y-3 font-mono text-sm text-tp-muted">
                    <li><span class="text-tp-teal-400">POST</span> /api/v1/epcis/capture <span class="font-sans text-tp-muted">(when accept_20 / capture enabled)</span></li>
                    <li><span class="text-tp-teal-400">GET</span> /api/v1/epcis/events <span class="font-sans text-tp-muted">(SimpleEventQuery subset)</span></li>
                    <li><span class="text-tp-teal-400">POST</span> /api/v1/wms/ship-confirm</li>
                    <li><span class="text-tp-teal-400">POST</span> /api/v1/dispense-check</li>
                </ul>
                <p class="mt-6 text-sm text-tp-muted">
                    Sanctum-authenticated, tenant-scoped. See docs/integrations for payloads and abilities.
                </p>
            </div>
        </div>
    </section>

    @include('marketing.partials.feature-related', ['current' => 'serialization'])
    <x-marketing.cta-banner
        title="Map your L3 ↔ L4 cutover"
        description="Request a demo—we'll align commissioning forward, Ship Order EPCIS, and partner exchange to your packaging lines and wholesaler customers."
    />
@endsection