@extends('marketing.layout')

@section('title', 'Pharmacy buying groups — TracePharma')
@section('meta_description', 'DSCSA network visibility for pharmacy buying groups: member health scorecards, exception trends, authorized partner matrix, and compliance snapshot APIs — without central inbound receiving.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Pharmacy buying groups"
        title="Network compliance visibility — without operating a central receiving hub"
        description="Before TracePharma, group executives often learn about member compliance gaps only when a wholesaler flags an authorization problem or an inspector calls. TracePharma gives buying group executives member verification health, exception trend alerts, and wholesaler authorization readiness—so you surface at-risk members before they become audit findings, without forcing the group to receive EPCIS centrally."
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
                ['phase' => 'Roster', 'title' => 'Member partners', 'description' => 'Register member pharmacies with primary wholesaler relationships.'],
                ['phase' => 'Monitor', 'title' => 'Network dashboard', 'description' => 'Open exceptions, attention counts, and at-risk member flags.'],
                ['phase' => 'Trend', 'title' => 'Exception trends', 'description' => '30-day current vs prior period per member — spot rising issues early.'],
                ['phase' => 'Authorize', 'title' => 'Partner matrix', 'description' => 'Member ↔ wholesaler license readiness before a bad ship-to.'],
                ['phase' => 'Report', 'title' => 'Compliance API', 'description' => 'Member compliance snapshots and exception trends for external BI.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Network dashboard', 'description' => 'Member count, open exceptions, and attention/at-risk summaries on day one—so executives see network health without calling each store.'],
                    ['title' => 'Member health scorecard', 'description' => 'Verify pass rate and wholesaler risk scoring per member pharmacy—so support teams prioritize stores trending down.'],
                    ['title' => 'Exception trends', 'description' => 'Rolling 30-day exception comparison—so rising issues surface before they become inspection findings.'],
                    ['title' => 'Authorized partner matrix', 'description' => 'License readiness across member ↔ wholesaler pairings—so bad ship-to authorization is caught before product moves.'],
                    ['title' => 'Member compliance API', 'description' => 'POST compliance snapshots from member sites—so centralized reporting stays current without manual member exports.'],
                    ['title' => 'Exception trends API', 'description' => 'GET member exception trends for external dashboards—so executive review uses live data, not quarterly spreadsheets.', 'href' => route('marketing.features.show', 'integrations')],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'Network-first profile', 'description' => 'Buying group tenancy gates off inbound receiving — the group monitors members, not warehouse operations.', 'items' => ['Member partner roster', 'Network dashboard as home', 'Profile-tuned navigation']],
                ['title' => 'Proactive member support', 'description' => 'Surface members trending up on exceptions before they become inspection findings.', 'items' => ['30-day exception trend charts', 'At-risk member counts', 'Wholesaler authorization gaps']],
                ['title' => 'API-ready reporting', 'description' => 'Push member verify pass rates and exception summaries to your existing BI stack.', 'items' => ['Compliance snapshot endpoint', 'Member exception trends export', 'API token management']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See buying group network visibility live"
        description="Request a buying group demo—we'll walk through member roster setup, network dashboard, exception trends, authorized partner matrix, and compliance API on a demo tenant."
    />
@endsection