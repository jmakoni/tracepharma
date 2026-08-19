@extends('marketing.layout')

@section('title', 'ERP integrations — TracePharma')
@section('meta_description', 'TracePharma alongside SAP ATTP and ERP systems—partner-edge L4 workflows via REST API and webhooks without replacing your corporate ERP.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Integrations · ERP"
        title="L4 traceability alongside your ERP—not instead of it"
        description="TracePharma rarely replaces corporate ERP or SAP Advanced Track and Trace. We handle partner-edge DSCSA workflows—receive, ship, exceptions, ACK monitoring—while your ERP remains the system of record for orders, inventory, and finance."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.integrations.index') }}">Integrations</a> / ERP
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.integrations.show', 'sap') }}">SAP ATTP integration →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                ERP buyers ask whether TracePharma replaces SAP, Oracle, or NetSuite. For most deployments, the answer is <strong class="text-tp-ink">no</strong>—TracePharma is the <strong class="text-tp-ink">L4 DSCSA application layer</strong> that speaks EPCIS to trading partners while your ERP handles POs, inventory valuation, and financial posting.
            </p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-2">
            <a href="{{ route('marketing.integrations.show', 'sap') }}" class="tp-card-accent group border-tp-accent-500/30 p-8 transition hover:border-tp-accent-500/50">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pulse ecosystem · ERP serialization</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">SAP Advanced Track and Trace (ATTP)</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">HTTPS SAP ICH preset when Integration Suite delivers EPCIS to TracePharma. ATTP stays the corporate serial repository; TracePharma runs partner-edge receive/ship and exceptions.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View SAP integration →</span>
            </a>
            <div class="tp-card p-8">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Other ERP platforms</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink">Oracle, NetSuite, Dynamics, custom ERP</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">No native ERP connector ships today. Integrate via REST API v1, outbound webhooks, and middleware that maps your ERP ship events to TracePharma capture endpoints—or use the WMS ship-confirm bridge when warehouse systems sit between ERP and the dock.</p>
                <div class="mt-5 flex flex-wrap gap-4">
                    <a href="{{ route('marketing.integrations.erp.show', 'oracle') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Oracle ERP Cloud →</a>
                    <a href="{{ route('marketing.integrations.erp.show', 'netsuite') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">NetSuite →</a>
                    <a href="{{ route('marketing.integrations.erp.show', 'dynamics365') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Dynamics 365 →</a>
                    <a href="{{ route('marketing.features.show', 'integrations') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">API &amp; webhooks →</a>
                </div>
            </div>
        </div>

        <h2 class="mt-14 text-xl font-semibold text-tp-ink">Common integration patterns</h2>
        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <x-marketing.detail-section
                title="ERP → WMS → TracePharma"
                :items="[
                    'ERP creates order; WMS executes pick/ship.',
                    'WMS ship-confirm webhook triggers outbound EPCIS on TracePharma.',
                    'See Manhattan and Körber integration pages.',
                ]"
            />
            <x-marketing.detail-section
                title="ERP → middleware → TracePharma"
                :items="[
                    'iPaaS or custom service POSTs EPCIS to /api/v1/epcis/capture.',
                    'Outbound webhooks push verification and exception events back to ERP or data lake.',
                    'AS2/SFTP paths for partners bypass ERP for traceability payloads.',
                ]"
            />
        </div>

        <p class="mt-8 text-sm text-tp-muted">
            Related:
            <a href="{{ route('marketing.integrations.wms.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">WMS integrations</a> ·
            <a href="{{ route('marketing.integrations.edi-as2') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">AS2 &amp; EDI</a>
        </p>
    </section>

    @php
        $erpFaqs = [
            ['question' => 'Does TracePharma replace SAP ATTP?', 'answer' => 'Usually no. ATTP remains the ERP serialization repository for SAP-centric manufacturers. TracePharma handles partner-facing L4 operations—receiving, outbound to specific partners, exceptions, and compliance exports.'],
            ['question' => 'Can we integrate Oracle or NetSuite today?', 'answer' => 'Via REST API and webhooks—not a certified Oracle or NetSuite connector. Most teams use middleware or WMS ship-confirm bridges depending on where serial custody events originate.'],
            ['question' => 'What about Microsoft Dynamics 365?', 'answer' => 'Same adjacency pattern as Oracle and NetSuite—REST capture and outbound webhooks via Logic Apps or Power Automate, or WMS ship-confirm when fulfillment runs in the warehouse layer. No certified Dynamics connector ships today.'],
        ];
    @endphp

    <x-marketing.faq :items="$erpFaqs" />

    <x-marketing.cta-banner
        title="Mapping ERP adjacency for DSCSA?"
        description="Describe your ERP, WMS, and partner transport paths—we'll show the integration pattern that fits."
    />
@endsection

@push('head')
    @php
        $faqSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $erpFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
