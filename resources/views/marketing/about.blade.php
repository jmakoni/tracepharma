@extends('marketing.layout')

@section('title', 'About TracePharma — L4 DSCSA traceability')
@section('meta_description', 'TracePharma builds L4 DSCSA traceability for US manufacturers, wholesalers, 3PLs, and dispensers—EPCIS partner exchange without replacing your ERP or PMS.')

@section('content')
    <x-marketing.page-hero
        eyebrow="About"
        title="L4 traceability built for operators—not slide decks"
        description="TracePharma is purpose-built software for US DSCSA partner-edge accountability: receive and ship EPCIS, verify serials, investigate exceptions, and export audit-ready compliance—on a profile tuned to how your team actually works."
    >
        <x-slot:breadcrumb>
            About
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <h2 class="text-xl font-semibold text-tp-ink">Our mission</h2>
            <p>
                US trading partners deserve interoperable traceability that works on the receiving desk, in the warehouse, and at dispense—not another opaque network with hidden fees. We build TracePharma as an <strong class="text-tp-ink">L4 corporate EPCIS hub</strong> that connects directly to partners via AS2, SFTP, and HTTPS while your ERP, WMS, and PMS stay the systems of record.
            </p>
            <p>
                We focus on <strong class="text-tp-ink">honest scope</strong>: no claim to replace SAP ATTP, Oracle, NetSuite, or your pharmacy system. We handle what DSCSA requires at the partner edge—3T matching, VRS verification, saleable returns, FDA 3911 support, and exception investigation—with operator UX tuned to manufacturers, wholesalers, 3PLs, prepackagers, buying groups, and dispensers.
            </p>
            <p>
                {{ \App\Support\Marketing\TermsOfService::productAttribution() }}
            </p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-3">
            <x-marketing.detail-section
                title="What we build"
                :items="[
                    'Per-tenant EPCIS ingest, outbound generation, and ACK monitoring.',
                    'Scan-first receiving and profile-tuned Filament navigation.',
                    'Direct partner connectivity—no mandatory exchange middleman.',
                ]"
            />
            <x-marketing.detail-section
                title="What we do not claim"
                :items="[
                    'Replacing corporate ERP or plant-floor L3 serialization.',
                    'Global FMD or L5 country hub coverage.',
                    'Pulse API certification today—we publish an honest roadmap.',
                ]"
            />
            <x-marketing.detail-section
                title="Who we serve"
                :items="[
                    'Drug manufacturers, wholesalers, and 3PLs.',
                    'Independent pharmacies, buying groups, prepackagers.',
                    'Dental and medical supply distributors.',
                ]"
            />
        </div>

        <p class="mt-10 text-sm text-tp-muted">
            Explore <a href="{{ route('marketing.features') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">features</a>,
            <a href="{{ route('marketing.integrations.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">integrations</a>, or
            <a href="{{ route('marketing.glossary') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">DSCSA glossary</a>.
        </p>
    </section>

    <x-marketing.cta-banner
        title="See TracePharma on your operating profile"
        description="Request a demo—we'll walk through receiving, outbound ship, verification, or compliance workflows tuned to your organization type."
    />
@endsection
