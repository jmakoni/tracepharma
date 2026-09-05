@extends('marketing.layout')

@section('title', 'Pharmacies — TracePharma')
@section('meta_description', 'DSCSA traceability for independent pharmacies: wholesaler EPCIS receiving, VRS verification, FDA 3911, POST /api/v1/dispense-check, and dispenser compliance scorecards.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Pharmacies"
        title="Receive, verify, dispense — without a six-figure enterprise contract"
        description="Before TracePharma, many independents verify at dispense but reconcile wholesaler PDFs by hand when a shipment arrives without matching EPCIS. TracePharma connects wholesaler EPCIS receiving, VRS verification before dispense, exception resolution, and FDA 3911 in one tenant workspace sized for independent and small-chain pharmacies."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / Pharmacies
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a pharmacy demo →</a>
            <a href="{{ route('marketing.features.show', 'verification') }}">VRS verification →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.pipeline-steps
            :steps="[
                ['phase' => 'Receive', 'title' => 'Wholesaler EPCIS', 'description' => 'SFTP/webhook presets for Cardinal, McKesson, ABC, and regional distributors.'],
                ['phase' => 'Confirm', 'title' => 'Scan receiving', 'description' => 'Mobile-friendly scan-confirm against expected shipment lines.'],
                ['phase' => 'Verify', 'title' => 'VRS check', 'description' => 'Workstation verify or dispense-check API before sale.'],
                ['phase' => 'Report', 'title' => 'FDA 3911', 'description' => 'Prefill suspected illegitimate product reports from failures.'],
                ['phase' => 'Score', 'title' => 'Compliance', 'description' => 'Weekly verify pass rate and wholesaler exception breakdown.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'EPCIS receiving', 'description' => 'Upload, SFTP poll, or webhook—match what your wholesaler actually sends so receiving clerks confirm shipments before inventory updates.', 'href' => route('marketing.features.show', 'receiving')],
                    ['title' => 'VRS verification', 'description' => 'Interactive workstation with full verification history—so PIC and staff show auditors the same evidence they used at dispense.', 'href' => route('marketing.features.show', 'verification')],
                    ['title' => 'Dispense-check API', 'description' => 'POST /api/v1/dispense-check lets PMS middleware block fills until verification passes—named per-vendor PMS adapters are not GA.', 'href' => route('marketing.features.show', 'integrations')],
                    ['title' => 'Rx expiry dashboard', 'description' => 'Expiring inventory surfaced from verify and receive records—so staff rotate stock before expiry exceptions pile up.'],
                    ['title' => 'Dispenser scorecard', 'description' => 'Weekly pass rate, PMS blocked-reason trends, wholesaler risk—so owners see compliance health without exporting spreadsheets each week.'],
                    ['title' => 'Dispenser compliance dashboard', 'description' => 'TI/TS coverage, verify pass rates, and wholesaler exception trends—so PIC and owners see dispenser compliance health in one view.'],
                    ['title' => 'Store transfer', 'description' => 'Inter-store serialized transfers for small chains—so inventory moves between locations with traceable custody (chain profiles).'],
                    ['title' => 'FDA 3911', 'description' => 'Inspection-ready reports prefilled from verification exceptions—so suspected illegitimate product reports start from operational data, not blank forms.', 'href' => route('marketing.features.show', 'compliance')],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-2 lg:items-start">
            <div>
                <h2 class="text-xl font-semibold text-tp-ink">Why independents choose TracePharma</h2>
                <ul class="mt-6 space-y-4 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> <span><strong class="text-tp-ink">Days to go-live</strong> — Onboarding wizard with wholesaler presets and connection test, not months of consultant configuration.</span></li>
                    <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> <span><strong class="text-tp-ink">Transparent TCO</strong> — Full L4 workflows without Pharmacy Pro-scale enterprise pricing.</span></li>
                    <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> <span><strong class="text-tp-ink">Closed-loop traceability</strong> — Not a verify-only portal that leaves staff reconciling PDFs manually.</span></li>
                </ul>
            </div>
            <div class="tp-card p-8">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-tp-muted">Compared to dispenser-only tools</h3>
                <p class="mt-4 text-sm leading-relaxed text-tp-muted">
                    InfiniTrak and similar platforms excel at turnkey pharmacy onboarding. TracePharma adds wholesaler-grade EPCIS depth, exception investigation, and read-only event-store trace search (EPCIS 1.2 GA; 2.0 capture + query-as-2.0) when you need more than verify-only — at a price point sized for independents.
                </p>
                <a href="{{ route('marketing.compare.free-dscsa') }}" class="mt-6 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                    Why free DSCSA isn't free →
                </a>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-14 sm:px-6">
        <div class="tp-card border-tp-border p-6 text-sm text-tp-muted">
            <p class="font-semibold text-tp-ink">Read-only EPCIS repository for pharmacy IT</p>
            <p class="mt-2 leading-relaxed">Pharmacy profiles get a read-only event repository for trace queries and investigations (EPCIS 1.2 GA; query-as-2.0 JSON-LD API)—not a capture-and-ship serialization hub. Manufacturer, wholesaler, 3PL, and prepackager profiles get inbound 2.0 JSON-LD capture (when enabled), outbound 1.2 by default, and optional HTTPS 2.0 subscription webhooks on the same canonical store.</p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-14 sm:px-6">
        <div class="tp-card border-tp-border p-6 text-sm text-tp-muted">
            <p class="font-semibold text-tp-ink">Also operating as a wholesaler or distributor?</p>
            <p class="mt-2 leading-relaxed">Some pharmacy organizations hold a distributor license. See the <a href="{{ route('marketing.solutions.wholesalers') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">drug wholesaler solution</a> for receive-to-ship and outbound ACK workflows.</p>
        </div>
    </section>

    <x-marketing.cta-banner
        title="See pharmacy workflows live"
        description="Request a pharmacy demo—we'll tailor the walkthrough to your wholesaler files, PMS integration path, and VRS setup."
    />
@endsection