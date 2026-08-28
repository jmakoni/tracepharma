@extends('marketing.layout')

@section('title', '3PL & logistics — TracePharma')
@section('meta_description', 'Level 4 DSCSA for 3PL and contract logistics: wholesaler-class receive/ship today, soft principal labels on sites and ship orders, cross-dock transfers, and multi-facility operations — EPC custody isolation remains on the roadmap.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · 3PL & logistics"
        title="Wholesaler-class L4 floor for contract logistics — soft principal tags today"
        description="TracePharma today gives 3PL tenants the same receive → transfer → ship → VRS → exceptions spine as regional wholesalers, plus multi-facility sites, WMS ship-confirm, and a soft principal registry (labels/filters on sites and ship orders). True multi-principal custody-isolated inventory and scorecards remain on the product roadmap."
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
                ['phase' => 'Label', 'title' => 'Principals (soft)', 'description' => 'Registry + optional site/ship-order tags and list filters — not EPC custody walls.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Wholesaler-class floor', 'description' => '3PL profile inherits receive, transfer, pack, commission, return, and outbound ship — same TenantFeatures as drug wholesaler today.'],
                    ['title' => 'Cross-dock transfer', 'description' => 'Move inventory between GLNs with staged scan verification—so facility transfers stay auditable.'],
                    ['title' => 'Lot-level shipping', 'description' => 'Ship non-serialized lines by GTIN + lot + quantity alongside serialized units on one outbound EPCIS drop.'],
                    ['title' => 'SSCC labeling', 'description' => 'Generate pallet labels with pool low-water alerts before serial pools run dry.'],
                    ['title' => 'WMS ship-confirm bridge', 'description' => 'POST /api/webhooks/wms/{tenantId} or Sanctum POST /api/v1/wms/ship-confirm — vendor-agnostic, not a per-vendor URL path.', 'href' => route('marketing.features.show', 'integrations')],
                    ['title' => 'Principal registry (soft)', 'description' => 'Name/GLN principals and optional tags on sites and ship orders for list filters — not custody-isolated inventory partitions.'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'What ships today', 'description' => '3PL = Logistics3pl profile with wholesaler-class floor flags.', 'items' => ['Receive / transfer / ship / VRS / exceptions', 'Soft principal registry + site/ship filters', 'WMS ship-confirm webhook + Sanctum']],
                ['title' => 'What is next', 'description' => 'True multi-client 3PL product depth.', 'items' => ['EPC-level custody isolation per principal', 'Principal filters on scorecards', 'Principal-scoped exception reporting']],
                ['title' => 'DSCSA distributor obligations', 'description' => 'Receive serialized product, verify transaction data, and ship with attached TI/TH/TS.', 'items' => ['EPCIS 1.2 GA + 2.0 capture/query/subscriptions', 'Transaction search at scale', 'In-app operations scorecards (compliance Sanctum APIs not GA)']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See 3PL floor workflows live"
        description="Request a 3PL demo—we'll walk through inbound manufacturer EPCIS, cross-dock transfer, lot-level ship, WMS ship-confirm, and soft principal tagging honestly."
    />
@endsection
