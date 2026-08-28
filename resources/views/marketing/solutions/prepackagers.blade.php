@extends('marketing.layout')

@section('title', 'Prepackagers & repackagers — TracePharma')
@section('meta_description', 'Level 4 DSCSA for contract packagers and repackagers: bulk receive, repack lineage, outbound EPCIS with new serials, decommission, and L3 commissioning forward.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Prepackagers & repackagers"
        title="Bulk in, repack out — serial lineage your QA team can defend"
        description="Before TracePharma, contract packagers often lose parent-child serial lineage across repack hops—forcing QA to rebuild trace history from email when a customer asks. TracePharma is the L4 corporate hub for contract packagers: receive manufacturer bulk EPCIS, generate repack outbound with new serials, trace parent-child lineage across multi-hop chains, and decommission scrap with audit-ready EPCIS."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / Prepackagers
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a prepack demo →</a>
            <a href="{{ route('marketing.features.show', 'serialization') }}">L3 ↔ L4 handoff →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.pipeline-steps
            :steps="[
                ['phase' => 'Receive', 'title' => 'Bulk manufacturer inbound', 'description' => 'EPCIS receiving with expected-shipment match and exception routing.'],
                ['phase' => 'Repack', 'title' => 'New serial generation', 'description' => 'Repack workflow assigns child serials with parent lineage preserved.'],
                ['phase' => 'Ship', 'title' => 'Outbound EPCIS', 'description' => 'Ship repacked product to wholesalers with full TI/TH/TS.'],
                ['phase' => 'Trace', 'title' => 'Lineage panel', 'description' => 'Product trace shows ancestor and descendant serials across hops.'],
                ['phase' => 'Destroy', 'title' => 'Decommission', 'description' => 'Scrap and sample destruction events for QA audits.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Prepack / repack workflow', 'description' => 'Assign new serials from bulk parent inventory with validation before outbound ship—so repack runs cannot ship without lineage recorded.'],
                    ['title' => 'Multi-hop lineage', 'description' => 'Product trace surfaces repack chains—not just single-hop parent/child—so QA answers multi-step lineage without spreadsheet reconstruction.', 'href' => route('marketing.features.show', 'compliance')],
                    ['title' => 'L3 commissioning forward', 'description' => 'Configure an L3 forward URL in Organization settings; authored commissioning EPCIS POSTs to your plant endpoint (idempotent)—no allocation export API.', 'href' => route('marketing.features.show', 'serialization')],
                    ['title' => 'Decommission & scrap', 'description' => 'Destroy and sample workflows with EPCIS events—so scrap and sample pulls stay in the compliance package auditors expect.'],
                    ['title' => 'Operations scorecards', 'description' => 'Outbound volume, ACK health, and commissioning forward status—so throughput and partner issues surface before repack backlogs delay ship.'],
                    ['title' => 'Outbound ACK monitoring', 'description' => 'Track wholesaler customer acknowledgments on repack shipments—so stale ACKs are visible before customers call about missing serials.'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'Repackager profile', 'description' => 'Navigation tuned for contract packaging — bulk receive, repack, ship, and trace without dispenser workflows.', 'items' => ['Prepack/repack page', 'Lineage in product trace', 'Manufacturer supplier scorecard']],
                ['title' => 'DSCSA repack obligations', 'description' => 'Generate compliant outbound serialization and maintain serial-level audit trail across repack events.', 'items' => ['New serial on repack', 'Parent serial linkage', 'EPCIS 1.2 GA + 2.0 capture/query/subscriptions']],
                ['title' => 'Plant-floor handoff', 'description' => 'Works with standard line serialization platforms — keep your L3; TracePharma forwards commissioning EPCIS from Organization settings.', 'items' => ['L3 forward URL in settings', 'Idempotent commissioning POST', 'Operations scorecard visibility']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See prepackager workflows live"
        description="Request a prepack demo—we'll walk through bulk receive, repack with new serials, lineage trace, outbound ship, and decommission on a demo tenant."
    />
@endsection