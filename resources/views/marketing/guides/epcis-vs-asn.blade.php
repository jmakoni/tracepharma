@extends('marketing.layout')

@section('title', 'EPCIS vs ASN for DSCSA — TracePharma guide')
@section('meta_description', 'When trading partners send EPCIS vs ASN files, what receivers need for DSCSA, and how TracePharma handles both—for dispensers, wholesalers, and 3PLs.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Guide"
        title="EPCIS vs ASN: what trading partners actually send"
        description="Manufacturers, wholesalers, and 3PLs use different file types to describe the same physical shipment. Know the difference so your receiving team does not treat a logistics notice as a traceability record."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.features') }}">Features</a> / EPCIS vs ASN
        </x-slot:breadcrumb>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-6 leading-relaxed text-tp-muted">
            <p>
                Under DSCSA, trading partners must exchange transaction information (TI), transaction history (TH), and a transaction statement (TS)—collectively the <strong class="text-tp-ink">3T</strong>—and maintain interoperable traceability for serialized product. In practice, partners deliver accountability through <strong class="text-tp-ink">EPCIS</strong> event files, while warehouse and ERP systems often speak <strong class="text-tp-ink">ASN</strong> (Advance Ship Notice) or proprietary CSV formats.
            </p>
            <p>
                A DSCSA program breaks when teams treat those documents as interchangeable. An ASN tells you <em>what was picked and shipped</em>; EPCIS tells you <em>which serials changed custody</em> in a standards-based event log.
            </p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-2">
            <div class="tp-card p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-tp-ink">ASN (Advance Ship Notice)</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                    Typically an EDI 856 or wholesaler-specific CSV tied to your PO. Useful for receiving dock workflow: line items, quantities, lot numbers, sometimes serials.
                </p>
                <ul class="mt-5 space-y-2 text-sm text-tp-ink/75">
                    <li>✓ Matches purchase order to physical cases</li>
                    <li>✓ Drives put-away and invoice reconciliation</li>
                    <li>✗ Not a GS1 EPCIS event document</li>
                    <li>✗ May omit serial-level aggregation hierarchy</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-6 text-tp-ink sm:p-8">
                <h2 class="text-lg font-semibold">EPCIS (Electronic Product Code Information Services)</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                    GS1 standard XML/JSON describing <code class="rounded bg-black/30 px-1 font-mono text-xs">ObjectEvent</code> and <code class="rounded bg-black/30 px-1 font-mono text-xs">AggregationEvent</code> records—who shipped which serials, when, and from which GLN.
                </p>
                <ul class="mt-5 space-y-2 text-sm text-tp-ink">
                    <li>✓ DSCSA interoperable traceability payload</li>
                    <li>✓ Serial-level chain of custody</li>
                    <li>✓ Feeds verification and exception workflows</li>
                    <li>✓ Supports AS2 and webhook automation</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Side-by-side comparison</h2>
            <div class="tp-marketing-table mt-8">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-tp-ink">Question</th>
                            <th class="px-4 py-3 text-left font-semibold text-tp-ink">ASN / CSV</th>
                            <th class="px-4 py-3 text-left font-semibold text-tp-teal-400">EPCIS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Primary purpose</td>
                            <td class="px-4 py-3 text-tp-muted">Logistics & receiving match</td>
                            <td class="px-4 py-3 text-tp-accent-300">Traceability & custody transfer</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Serial accountability</td>
                            <td class="px-4 py-3 text-tp-muted">Sometimes partial</td>
                            <td class="px-4 py-3 text-tp-accent-300">Required for serialized events</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">DSCSA 3T</td>
                            <td class="px-4 py-3 text-tp-muted">May be separate PDF/email</td>
                            <td class="px-4 py-3 text-tp-accent-300">Often bundled with shipment events</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Downstream verification</td>
                            <td class="px-4 py-3 text-tp-muted">Not sufficient alone</td>
                            <td class="px-4 py-3 text-tp-accent-300">Populates serial inventory for VRS</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Typical delivery</td>
                            <td class="px-4 py-3 text-tp-muted">SFTP, portal download</td>
                            <td class="px-4 py-3 text-tp-accent-300">SFTP, AS2, API webhook</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">What to do at receiving</h2>
        <p class="mt-3 max-w-3xl text-sm leading-relaxed text-tp-muted">
            The pattern is the same whether you are a pharmacy, wholesaler DC, or 3PL warehouse: match physically, ingest EPCIS into your L4 hub, resolve gaps before inventory moves downstream.
        </p>
        <div class="mt-8 grid gap-6 md:grid-cols-3">
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Step 1</p>
                <p class="mt-3 font-semibold text-tp-ink">Match the shipment physically</p>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Confirm cases and quantities against ASN or packing slip.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Step 2</p>
                <p class="mt-3 font-semibold text-tp-ink">Ingest EPCIS into traceability</p>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Load partner EPCIS into TracePharma before shipping downstream or dispensing serialized product.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Step 3</p>
                <p class="mt-3 font-semibold text-tp-ink">Resolve exceptions in-app</p>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Missing serials or 3T gaps become tracked exceptions—not sticky notes on the dock.</p>
            </div>
        </div>

        <div class="tp-alert--warning mt-10 p-6 text-sm leading-relaxed text-tp-ink">
            <strong class="text-tp-warning">Common pitfall:</strong> Storing only ASN data in a “free” portal without EPCIS event processing means you cannot prove serial-level custody when VRS fails or FDA asks for trace history. TracePharma ingests EPCIS through upload, SFTP, AS2, and webhooks—and surfaces gaps immediately.
        </div>

        <div class="mt-8 flex flex-wrap gap-4">
            <a href="{{ route('marketing.glossary.show', 'epcis') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">What is EPCIS? →</a>
            <a href="{{ route('marketing.glossary.show', 'asn') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">What is ASN? →</a>
            <a href="{{ route('marketing.features.show', 'receiving') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Receiving features →</a>
            <a href="{{ route('marketing.compare.checklist') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Provider checklist →</a>
        </div>
    </section>

    <x-marketing.cta-banner
        title="Not sure what your partners send?"
        description="Share a redacted sample file—we'll identify whether you're getting traceability-ready EPCIS or logistics-only ASN data."
    />

    <x-marketing.checklist-sticky-bar />
@endsection