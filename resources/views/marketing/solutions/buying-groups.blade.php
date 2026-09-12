@extends('marketing.layout')

@section('title', 'Pharmacy buying groups — TracePharma')
@section('meta_description', 'DSCSA control-plane for pharmacy buying groups: member roster, health scorecards, authorized partner matrix, partner ATP readiness, compliance alerts, and member APIs — without operating a central receiving hub.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Pharmacy buying groups"
        title="Control-plane visibility — without operating a central receiving hub"
        description="Buying group tenancy in TracePharma is a network control plane: member roster, member health, authorized partner matrix, partner ATP readiness, compliance alerts, and member APIs for executives — without enabling warehouse receive/ship for the group entity. Legal DSCSA duties stay with member dispensers."
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
                ['phase' => 'Roster', 'title' => 'Member roster', 'description' => 'GA CRUD for member pharmacies (link/status, invite/consent, CSV export) under Compliance.'],
                ['phase' => 'Health', 'title' => 'Network health & matrix', 'description' => 'Daily rollup snapshots: member health scorecards and member ↔ wholesaler licence matrix.'],
                ['phase' => 'APIs', 'title' => 'Member APIs & alerts', 'description' => 'Sanctum network APIs plus compliance alert center — control-plane JSON, no EPC payloads.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Network-first profile', 'description' => 'Buying group tenancy keeps receive, ship, and master-data CRUD off — executives get a focused control-plane surface.'],
                    ['title' => 'Member roster (GA)', 'description' => 'CRUD roster of member pharmacies (name, optional external ref / linked tenant, status, contact email) with invite/consent and CSV export.'],
                    ['title' => 'Partner ATP readiness', 'description' => 'Shipped control-plane page for upstream partner facility licence readiness in your jurisdictions.'],
                    ['title' => 'Compliance alert center', 'description' => 'Shipped alert shell for integration/ATP signals and BG network alerts without opening warehouse exception workstations.'],
                    ['title' => 'Member health (GA)', 'description' => 'Health scorecards, at-risk flags, and exception-trend snapshots from consented hard-linked pharmacy members.'],
                    ['title' => 'Authorized partner matrix (GA)', 'description' => 'Member ↔ wholesaler licence matrix from daily rollup facts in the buying-group tenant DB.'],
                    ['title' => 'Member APIs (GA)', 'description' => 'Sanctum /api/v1/buying-group/* — members, readiness, network summary, partner-matrix (no EPC payloads).'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'What ships today (GA)', 'description' => 'Control-plane surfaces for buying group tenants.', 'items' => ['Buying group profile (no floor ops)', 'Member roster (CRUD) + invite/consent', 'Member health scorecards', 'Authorized partner matrix', 'Member compliance APIs', 'Partner ATP readiness', 'Compliance alert center', 'Program affiliation code']],
                ['title' => 'What stays off', 'description' => 'The group is not a warehouse.', 'items' => ['Inbound EPCIS receiving', 'Outbound ship / WMS', 'Site/device master-data CRUD', 'BG as ATP substitute for members', 'Live EPC fan-out from member tenancy']],
                ['title' => 'Honest scope / not claimed', 'description' => 'Control plane — not channel enablement theater alone.', 'items' => ['Soft roster + hard link with consent', 'Snapshot rollups (not live EPC fan-out)', 'Members remain DSCSA trading partners', 'Full enrollment analytics / white-label CTA copy stay deferred']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See buying group control-plane visibility live"
        description="Request a buying group demo—we'll walk through the member roster, health and partner matrix, ATP readiness, alert center, and member APIs."
    />
@endsection
