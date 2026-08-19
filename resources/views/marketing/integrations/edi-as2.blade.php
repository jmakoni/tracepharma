@extends('marketing.layout')

@section('title', 'AS2 & EDI middleware — TracePharma integrations')
@section('meta_description', 'TracePharma AS2 receive, SFTP polling, and Axway B2B gateway patterns for EPCIS—EDI transport without replacing your L4 traceability hub.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Integrations · AS2 & EDI"
        title="EPCIS over AS2, SFTP, and EDI middleware"
        description="DSCSA accountability travels as EPCIS—not generic EDI 850 purchase orders. TracePharma receives signed AS2 messages, polls SFTP inboxes, and accepts HTTPS webhooks from Axway and other B2B gateways."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.integrations.index') }}">Integrations</a> / AS2 &amp; EDI
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.integrations.show', 'axway') }}">Axway integration →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                Buyers often ask for “EDI integration.” In DSCSA programs, the critical payload is <strong class="text-tp-ink">EPCIS</strong> (serial-level custody events). It is often delivered via <strong class="text-tp-ink">AS2</strong> with signed MDN—not ASN X12 alone. TracePharma ingests EPCIS through native AS2, SFTP polling, and HTTPS capture.
            </p>
            <p>
                Warehouse ASNs (EDI 856) remain useful for dock workflow; TracePharma matches them to EPCIS where both arrive. See the
                <a href="{{ route('marketing.guides.epcis-vs-asn') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">EPCIS vs ASN guide</a>
                for the distinction.
            </p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-2">
            <x-marketing.detail-section
                title="Native AS2"
                :items="[
                    'Inbound AS2 receive with partner certificate registration.',
                    'Signed MDN handling and ACK tracking in integration health.',
                    'Used by McKesson AS2 preset, TraceLink partners, and custom manufacturer connections.',
                ]"
            />
            <x-marketing.detail-section
                title="SFTP polling"
                :items="[
                    'Scheduled poll of wholesaler and partner SFTP inboxes.',
                    'Cardinal and Cencora presets ship with path and format hints.',
                    'Processed-file archival and exception surfacing on failed parses.',
                ]"
            />
            <x-marketing.detail-section
                title="EDI / B2B middleware"
                :items="[
                    'Axway and similar gateways forward EPCIS to TracePharma HTTPS or AS2 endpoints.',
                    'Enterprise EDI teams keep existing partner profiles; TracePharma is the L4 application layer.',
                    'See Axway interoperability page for gateway cutover checklist.',
                ]"
            />
            <x-marketing.detail-section
                title="HTTPS & REST"
                :items="[
                    'McKesson HTTPS JSON, LSPedia webhooks, Gateway Checker push.',
                    'POST /api/v1/epcis/capture for partner REST delivery.',
                    'Outbound webhooks for downstream ERP/WMS automation.',
                ]"
            />
        </div>

        <h2 class="mt-14 text-xl font-semibold text-tp-ink">Related integration pages</h2>
        <ul class="mt-6 space-y-3 text-sm">
            <li><a href="{{ route('marketing.integrations.show', 'axway') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Axway B2B gateway →</a></li>
            <li><a href="{{ route('marketing.integrations.show', 'tracelink') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">TraceLink AS2/SFTP →</a></li>
            <li><a href="{{ route('marketing.integrations.wholesale.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Wholesaler inbound presets →</a></li>
            <li><a href="{{ route('marketing.integrations.wholesale.show', 'mckesson-as2') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">McKesson AS2 preset →</a></li>
        </ul>
    </section>

    @php
        $ediFaqs = [
            ['question' => 'Does TracePharma translate EDI 850/856 into EPCIS?', 'answer' => 'TracePharma is EPCIS-native. ASNs and EDI documents can supplement receiving workflow, but DSCSA serial accountability requires EPCIS ingest. We match ASN data to EPCIS when both are present—not replace EPCIS with EDI alone.'],
            ['question' => 'Do I need Axway or another EDI vendor?', 'answer' => 'Only when partners or IT require a gateway. TracePharma supports direct AS2 and SFTP without middleware for many mid-market deployments.'],
        ];
    @endphp

    <x-marketing.faq :items="$ediFaqs" title="AS2, EDI & EPCIS FAQ" />

    <x-marketing.cta-banner
        title="Mapping AS2 certificates or SFTP paths?"
        description="Bring your partner spec—we'll configure inbound connections and test receiving on a demo tenant."
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
            ], $ediFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
