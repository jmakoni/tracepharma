@extends('marketing.layout')

@section('title', '3PL & logistics — TracePharma')
@section('meta_description', 'Level 4 DSCSA for 3PL and contract logistics: wholesaler-class receive/ship, soft principal registry and filters (GA), scorecard principal filters, and optional EPC custody enforcement (tenant ops setting; default off). Not an LSPedia Edge clone.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · 3PL & logistics"
        title="Wholesaler-class L4 floor for contract logistics — soft principal filters GA"
        description="TracePharma gives 3PL tenants the same receive → transfer → ship → VRS → exceptions spine as regional wholesalers, plus multi-facility sites, WMS ship-confirm, and a soft principal registry (labels/filters on sites, ship orders, scorecards, expiry, and HQ). Optional EPC custody enforcement is available as a tenant ops setting (default off); when enabled, serials are gated per principal. The default product promise stays soft filters—not serial isolation—unless enforcement is on for that tenant."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / 3PL &amp; logistics
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a 3PL demo →</a>
            <a href="{{ route('marketing.features.show', 'integrations') }}">Partner connectivity →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.pipeline-steps
            :steps="[
                ['phase' => 'Receive', 'title' => 'Inbound EPCIS', 'description' => 'Wholesaler-class receiving with scan-confirm and expected-shipment matching.'],
                ['phase' => 'Transfer', 'title' => 'Cross-dock', 'description' => 'Facility-to-facility transfers with immutable audit trail.'],
                ['phase' => 'Ship', 'title' => 'Lot & serial outbound', 'description' => 'Mixed lot-level and serialized ship orders with 3T documents.'],
                ['phase' => 'Bridge', 'title' => 'WMS ship-confirm', 'description' => 'Tenant webhook or Sanctum ship-confirm → outbound EPCIS drafts.'],
                ['phase' => 'Label', 'title' => 'Principals (soft + optional custody)', 'description' => 'Registry + list filters GA; optional EPC custody walls when tenant ops enables enforcement (default off).'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Wholesaler-class floor', 'description' => '3PL profile inherits receive, transfer, pack, return, and outbound ship — same distribution floor as drug wholesaler (no plant commission).'],
                    ['title' => 'Cross-dock transfer', 'description' => 'Move inventory between GLNs with staged scan verification—so facility transfers stay auditable.'],
                    ['title' => 'Lot-level shipping', 'description' => 'Ship non-serialized lines by GTIN + lot + quantity alongside serialized units on one outbound EPCIS drop.'],
                    ['title' => 'SSCC labeling', 'description' => 'Generate pallet labels with pool low-water alerts before serial pools run dry.'],
                    ['title' => 'WMS ship-confirm bridge', 'description' => 'POST /api/webhooks/wms/{tenantId} or Sanctum POST /api/v1/wms/ship-confirm — vendor-agnostic, not a per-vendor URL path.', 'href' => route('marketing.features.show', 'integrations')],
                    ['title' => 'Principal registry & filters', 'description' => 'Name/GLN principals with soft filters on sites, ship orders, scorecards, expiry, and HQ (GA). Optional EPC custody enforcement (ops setting; default off) gates serials per principal when enabled—not claimed as the default product promise.'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'What ships today', 'description' => '3PL = Logistics3pl profile with wholesaler-class floor flags.', 'items' => ['Receive / transfer / ship / VRS / exceptions', 'Soft principal registry + site/ship/scorecard filters (GA)', 'Optional EPC custody enforcement (ops setting; default off)']],
                ['title' => 'Honest product promise', 'description' => 'Default remains soft filters; isolation only when enforcement is on.', 'items' => ['Default: soft filters without claiming serial isolation', 'When enforced: pick/ship/receive walls + agent TI per principal', 'Not an LSPedia Edge / enterprise Edge suite / ATP DB product']],
                ['title' => 'DSCSA distributor obligations', 'description' => 'Receive serialized product, verify transaction data, and ship with attached TI/TH/TS.', 'items' => ['EPCIS 1.2 GA + 2.0 capture/query/subscriptions', 'Transaction search at scale', 'In-app operations scorecards (compliance Sanctum APIs not GA)']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See 3PL floor workflows live"
        description="Request a 3PL demo—we'll walk through inbound manufacturer EPCIS, cross-dock transfer, lot-level ship, WMS ship-confirm, soft principal filters, and optional custody enforcement honestly."
    />
@endsection
