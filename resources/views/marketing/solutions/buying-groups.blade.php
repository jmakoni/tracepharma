@extends('marketing.layout')

@section('title', 'Pharmacy buying groups — TracePharma')
@section('meta_description', 'DSCSA control-plane visibility for pharmacy buying groups: partner ATP readiness and compliance alert center today, with member network product on the roadmap — without operating a central receiving hub.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Pharmacy buying groups"
        title="Control-plane visibility — without operating a central receiving hub"
        description="Buying group tenancy in TracePharma is a network control plane: partner ATP readiness and compliance alerts for executives, without enabling warehouse receive/ship for the group entity. Full member roster dashboards, authorized-partner matrix, and member compliance APIs are on the product roadmap."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / Buying groups
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a buying group demo →</a>
            <a href="{{ route('marketing.features.show', 'compliance') }}">Compliance reporting →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.pipeline-steps
            :steps="[
                ['phase' => 'Profile', 'title' => 'Buying group tenant', 'description' => 'Dedicated profile with floor ops gated off — the group monitors, it does not receive EPCIS centrally.'],
                ['phase' => 'ATP', 'title' => 'Partner readiness', 'description' => 'Partner ATP readiness views for organization jurisdictions (control-plane shell).'],
                ['phase' => 'Alerts', 'title' => 'Alert center', 'description' => 'Compliance alert center for integration and ATP signals without quarantine/3911 floor work.'],
                ['phase' => 'Roadmap', 'title' => 'Member network', 'description' => 'Member health scorecards, partner matrix, and member APIs planned — not claimed as GA.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Network-first profile', 'description' => 'Buying group tenancy keeps receive, ship, and master-data CRUD off — executives get a focused control-plane surface.'],
                    ['title' => 'Partner ATP readiness', 'description' => 'Shipped control-plane page for upstream partner facility licence readiness in your jurisdictions.'],
                    ['title' => 'Compliance alert center', 'description' => 'Shipped alert shell for integration/ATP signals without opening warehouse exception workstations.'],
                    ['title' => 'Member roster (roadmap)', 'description' => 'Member pharmacy roster, health scorecards, and at-risk flags are planned — not GA endpoints today.'],
                    ['title' => 'Authorized partner matrix (roadmap)', 'description' => 'Member ↔ wholesaler licence matrix is planned for network GTM; use partner ATP readiness meanwhile.'],
                    ['title' => 'Member APIs (roadmap)', 'description' => 'Member compliance snapshot and exception-trend APIs are not shipped; do not point BI at /api/v1/compliance/* yet.'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'What ships today', 'description' => 'Control-plane shell for buying group tenants.', 'items' => ['Buying group profile (no floor ops)', 'Partner ATP readiness', 'Compliance alert center']],
                ['title' => 'What stays off', 'description' => 'The group is not a warehouse.', 'items' => ['Inbound EPCIS receiving', 'Outbound ship / WMS', 'Site/device master-data CRUD']],
                ['title' => 'What is next', 'description' => 'Network product backlog after Wave 0 unlock.', 'items' => ['Member roster + health', 'Partner authorization matrix', 'Member compliance APIs']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See buying group control-plane visibility live"
        description="Request a buying group demo—we'll walk through the buying group profile, partner ATP readiness, and alert center, and review the member-network roadmap honestly."
    />
@endsection
