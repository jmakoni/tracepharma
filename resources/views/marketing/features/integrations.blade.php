@extends('marketing.layout')

@section('title', 'Integrations — TracePharma features')
@section('meta_description', 'DSCSA REST API, outbound webhooks, AS2, SFTP, and tenant-scoped credentials in TracePharma.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Feature deep dive"
        title="Integrations & trading partner connectivity"
        description="Connect WMS, ERP, plant-floor systems, and trading partner automation in one tenant workspace—without bolting on a separate integration project."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.features') }}">Features</a> / Integrations
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-marketing.detail-section
                title="REST API (v1)"
                :items="[
                    'EPCIS upload and event query endpoints—so middleware and WMS teams automate without Filament UI steps.',
                    'Verification, dispense-check, and tracing requests—so PMS and robotics integrations call one tenant-scoped API.',
                    'Outbound shipment creation and confirmation—so ship events originate from systems your DC already runs.',
                ]"
            />
            <x-marketing.detail-section
                title="Outbound webhooks"
                :items="[
                    'Self-service HTTPS endpoint configuration per tenant.',
                    'Events: verification outcomes, exceptions, dispense checks.',
                    'Queued delivery with signing for downstream verification.',
                ]"
            />
            <x-marketing.detail-section
                title="Inbound automation"
                :items="[
                    'EPCIS inbound webhooks on the central domain with tenant routing.',
                    'AS2 receive and MDN handling for EPCIS partners.',
                    'SFTP polling for scheduled inbound and outbound ACK files.',
                    'Wholesaler presets for Cardinal SFTP, McKesson AS2/HTTPS, and Cencora SFTP.',
                ]"
            />
            <x-marketing.detail-section
                title="Outbound shipping"
                :items="[
                    'Generate EPCIS shipping documents and consolidated shipments.',
                    'SSCC label PDF generation for pallets and cases.',
                    'Partner-specific routing for acknowledgements.',
                ]"
            />
            <x-marketing.detail-section
                title="L3 serial provisioning (manufacturers)"
                :items="[
                    'REST API to create allocations and export serial ranges to plant-floor systems.',
                    'Inbound commissioning EPCIS reconciled against reserved allocations.',
                    'Presets for TraceLink, UniTrace, LSPedia, and regional connectivity vendors.',
                ]"
            />
            <x-marketing.detail-section
                title="Provider presets"
                :items="[
                    'SerializationProvider presets for AS2, SFTP, and HTTPS inbound paths.',
                    'Direct partner connectivity—no mandatory exchange network enrollment.',
                    'Gateway and middleware patterns (Axway → HTTPS webhook).',
                ]"
            />
            <x-marketing.detail-section
                title="Pharmacy PMS dispense check"
                :items="[
                    'Unified POST /api/v1/pms/{vendor}/dispense for PioneerRx, BestRx, PrimeRx, Liberty/Rx30, QS/1, EnterpriseRx, and ScriptPro.',
                    'Optional per-vendor shared-secret headers before completing a fill.',
                    'pms_dispense_events audit trail with Filament list/view and 30-day blocked-reason trends on the dispenser scorecard.',
                    'GET /api/v1/compliance/dispenser-scorecard embeds pms_blocked_reason_trends; standalone GET /api/v1/compliance/pms-blocked-reason-trends for BI.',
                ]"
            />
            <x-marketing.detail-section
                title="WMS ship-confirm bridge"
                :items="[
                    'POST /api/webhooks/wms/{tenantId}/{vendor}/ship-confirm for Manhattan Active WM and Körber (HighJump).',
                    'Normalizes ship-confirm JSON into outbound EPCIS shipment drafts with optional auto-queue.',
                    'wms_ship_confirm_events audit trail with Filament list/view and 30-day blocked-reason trends on the operations scorecard.',
                    'GET /api/v1/compliance/operations-scorecard embeds wms_ship_confirm_blocked_reason_trends; standalone GET /api/v1/compliance/wms-ship-confirm-trends for BI.',
                ]"
            />
        </div>
        <p class="mt-8 text-sm text-tp-muted">
            Platform guides:
            <a href="{{ route('marketing.integrations.pms.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">PMS integrations</a> ·
            <a href="{{ route('marketing.integrations.wms.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">WMS integrations</a> ·
            <a href="{{ route('marketing.integrations.wholesale.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Wholesaler presets</a> ·
            <a href="{{ route('marketing.integrations.edi-as2') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">AS2 &amp; EDI</a> ·
            <a href="{{ route('marketing.integrations.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Serialization vendors</a> ·
            <a href="{{ route('marketing.features.show', 'serialization') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">L3 serialization</a>
        </p>
    </section>

    @include('marketing.partials.feature-related', ['current' => 'integrations'])
    <x-marketing.cta-banner title="Review your integration map" description="Request a demo—we'll align API, webhook, AS2, and WMS endpoints to your architecture for manufacturer L3 handoff or distributor outbound." />
@endsection
