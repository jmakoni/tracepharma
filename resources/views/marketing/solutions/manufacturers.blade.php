@extends('marketing.layout')

@section('title', 'Drug manufacturers — TracePharma')
@section('meta_description', 'Level 4 traceability for drug manufacturers: L3 commissioning forward, outbound EPCIS, wholesaler ACK monitoring, saleable returns, and operations scorecards.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Drug manufacturers"
        title="Your L4 hub from packaging line to wholesaler dock"
        description="Before TracePharma, serialization IT teams often reconcile L3 commissioning in spreadsheets while supply chain chases stale wholesaler ACKs by email. TracePharma connects plant-floor serialization to corporate traceability—forward authored commissioning EPCIS to your L3 endpoint, ship DSCSA-compliant outbound, and monitor downstream partner health without a global network middleman."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / Drug manufacturers
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a manufacturer demo →</a>
            <a href="{{ route('marketing.features.show', 'serialization') }}">L3 ↔ L4 serialization →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold text-tp-ink">Built for brand-owner operations</h2>
            <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                Manufacturers ship serialized product—they do not run dispenser verify workflows. TracePharma gates the tenant profile so operators focus on outbound serialization, customer ACK health, and saleable returns.
            </p>
        </div>

        <x-marketing.pipeline-steps
            class="mt-8"
            :steps="[
                ['phase' => 'Configure', 'title' => 'L3 forward URL', 'description' => 'Set your plant-floor or enterprise L3 HTTPS endpoint and credentials in Organization settings.'],
                ['phase' => 'Author', 'title' => 'Commissioning EPCIS', 'description' => 'TracePharma authors commissioning documents as the L4 corporate hub.'],
                ['phase' => 'Forward', 'title' => 'Hand off to L3', 'description' => 'ForwardCommissioningToL3 POSTs commissioning XML to your endpoint—idempotent, no allocation export API.'],
                ['phase' => 'Ship', 'title' => 'Outbound EPCIS', 'description' => 'Generate shipping events with TI, TH, and TS for wholesaler customers.'],
                ['phase' => 'Monitor', 'title' => 'ACK & scorecard', 'description' => 'Track customer acknowledgement health and operations scorecard metrics.'],
            ]"
        />
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2 lg:items-start">
            <x-marketing.l4-stack />
            <div class="text-sm leading-relaxed text-tp-muted">
                <p>TracePharma remains the L4 hub while your plant-floor L3 stays in place. Configure commissioning forward in Organization settings; outbound EPCIS flows to wholesaler customers. National regulatory hubs (L5) remain outside our product—you connect partners directly.</p>
                <a href="{{ route('marketing.compare.lspedia') }}" class="mt-4 inline-flex font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Compare vs LSPedia OneScan →</a>
            </div>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="max-w-2xl">
                <h2 class="text-xl font-semibold text-tp-ink">Manufacturer module map</h2>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                    One tenant workspace—no separate serialization backend bolted on after go-live.
                </p>
            </div>

            <x-marketing.module-grid
                class="mt-8"
                :modules="[
                    [
                        'icon' => 'L3↔L4',
                        'title' => 'L3 commissioning forward',
                        'description' => 'Configure an L3 HTTPS endpoint in Organization settings; authored commissioning EPCIS can POST to that endpoint (idempotent)—no public allocation API.',
                        'href' => route('marketing.features.show', 'serialization'),
                    ],
                    [
                        'icon' => 'OUT',
                        'title' => 'Outbound shipping EPCIS',
                        'description' => 'Ship serialized product to wholesalers with attached TI, TH, and TS—so customers receive compliant transaction data on every outbound drop.',
                        'href' => route('marketing.features.show', 'integrations'),
                    ],
                    [
                        'icon' => 'ACK',
                        'title' => 'Customer ACK monitoring',
                        'description' => 'Outbound message monitor with stale-ACK alerts—so supply chain sees partner health before a customer calls about missing serials.',
                        'href' => route('marketing.features.show', 'integrations'),
                    ],
                    [
                        'icon' => 'RTN',
                        'title' => 'Saleable returns',
                        'description' => 'Receive return EPCIS from wholesalers and reconcile returned serials—so saleable returns stay tied to original outbound history.',
                    ],
                    [
                        'icon' => 'OPS',
                        'title' => 'Operations scorecards',
                        'description' => 'Outbound volume, ACK health, and commissioning forward status—so serialization IT sees partner and handoff health without a line-heartbeat API.',
                    ],
                    [
                        'icon' => 'TRC',
                        'title' => 'Product trace',
                        'description' => 'Search serial history across commissioning, ship, and return events—so QA answers trace requests without rebuilding timelines from email.',
                    ],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold text-tp-ink">DSCSA compliance pillars</h2>
            <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                What auditors and wholesaler customers expect from a manufacturer L4—not a checkbox portal.
            </p>
        </div>

        <x-marketing.compliance-pillars
            class="mt-8"
            :pillars="[
                [
                    'title' => 'Outbound transaction data',
                    'description' => 'Every shipment carries the TI, TH, and TS your customers need for DSCSA receiving and verification.',
                    'items' => [
                        'EPCIS 1.2 GA outbound; 2.0 JSON-LD capture + query-as-2.0; outbound 1.2 default (partner opt-in for 2.0)',
                        'Customer partner GLN routing',
                        'Consolidated and quick outbound paths',
                    ],
                ],
                [
                    'title' => 'Authorized trading partners',
                    'description' => 'Validate wholesaler and distributor licenses before you ship serialized product.',
                    'items' => [
                        'ATP license fields on trading partners',
                        'Daily license revalidation schedule',
                        'Partner cockpit for customer health',
                    ],
                ],
                [
                    'title' => 'Audit-ready evidence',
                    'description' => 'Immutable activity log, trace search, and compliance export when FDA or a customer asks for proof.',
                    'items' => [
                        'Operations scorecard API',
                        'Compliance export JSON/CSV',
                        'Commissioning gap visibility on scorecard',
                    ],
                ],
            ]"
        />
    </section>

    <section class="border-t border-tp-border bg-tp-surface/30">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-xl font-semibold text-tp-ink">Why manufacturers choose TracePharma</h2>
                    <ul class="mt-6 space-y-4 text-sm leading-relaxed text-tp-muted">
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> <span><strong class="text-tp-ink">Direct wholesaler connectivity</strong> — AS2, SFTP, and HTTPS to McKesson, Cardinal, ABC, and regional partners. No per-transaction network fees.</span></li>
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> <span><strong class="text-tp-ink">Your L3, our L4</strong> — Keep your plant-floor serialization software. TracePharma is the corporate serial authority and EPCIS hub.</span></li>
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> <span><strong class="text-tp-ink">Mid-market TCO</strong> — Enterprise traceability depth without TraceLink-scale implementation timelines.</span></li>
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> <span><strong class="text-tp-ink">Operator UX</strong> — Persona-based navigation for serialization IT, QA, and supply chain—not a dashboard only consultants can configure.</span></li>
                    </ul>
                </div>
                <div class="tp-card p-8">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-tp-muted">Compared to enterprise L4 suites</h3>
                    <p class="mt-4 text-sm leading-relaxed text-tp-muted">
                        TraceLink, UniTrace, and OPTEL serve global MAH programs well. TracePharma wins when you need US DSCSA outbound operations, ACK monitoring, and L3 handoff—without funding fifteen global regulation modules you will never turn on.
                    </p>
                    <table class="mt-6 w-full text-left text-xs text-tp-muted">
                        <thead>
                            <tr class="border-b border-tp-border text-tp-muted">
                                <th class="pb-2 pr-3 font-medium">If prospect mentions</th>
                                <th class="pb-2 font-medium">Primary competitor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr><td class="py-2 pr-3">Network scale, Opus</td><td class="py-2">TraceLink</td></tr>
                            <tr><td class="py-2 pr-3">Line software + corporate hub</td><td class="py-2">UniTrace (we integrate with your L3)</td></tr>
                            <tr><td class="py-2 pr-3">Fixed-fee unlimited serials</td><td class="py-2">OPTEL VerifyBrand</td></tr>
                            <tr><td class="py-2 pr-3">Full US DSCSA module suite</td><td class="py-2">LSPedia OneScan</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-4 text-xs text-tp-muted">
                        Sales engineers: full vendor matrix and battlecards in <strong class="text-tp-muted">Admin → Documentation → L4 competitive landscape</strong>.
                    </p>
                    <a href="{{ route('marketing.compare.lspedia') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                        LSPedia alternative comparison →
                    </a>
                    <a href="{{ route('marketing.features') }}" class="mt-4 ml-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                        Full feature catalog →
                    </a>
                </div>
            </div>
        </div>
    </section>

    <x-marketing.cta-banner
        title="See manufacturer workflows live"
        description="Request a manufacturer demo—we'll walk through L3 commissioning forward, outbound ship to a wholesaler customer, ACK monitoring, and saleable return receive on a demo tenant."
    />
@endsection