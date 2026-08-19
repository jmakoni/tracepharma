@extends('marketing.layout')

@section('title', 'EPCIS receiving — TracePharma features')
@section('meta_description', 'Inbound EPCIS, 3T documents, scan-first shipment matching, and product trace for DSCSA receiving in warehouses, 3PLs, and dispensers.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Feature deep dive"
        title="EPCIS receiving & shipment matching"
        description="Ingest shipment data through the channels your partners use. Missing serials and 3T gaps surface before product hits inventory. Built for warehouse scan-first workflows; pharmacies and 3PLs share the same L4 receiving engine."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.features') }}">Features</a> / Receiving
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.guides.epcis-vs-asn') }}">EPCIS vs ASN guide →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-marketing.detail-section
                title="Inbound channels"
                :items="[
                    'Manual EPCIS upload from the app or REST API—so ad hoc partner files still enter the same validation path.',
                    'SFTP polling for scheduled wholesaler drops—so inbound files arrive without manual upload each morning.',
                    'AS2 and HTTPS webhooks for partner automation—so EPCIS lands in your tenant as partners send it.',
                ]"
            />
            <x-marketing.detail-section
                title="Shipment receipt review"
                :items="[
                    'Operator-friendly status when files fail validation or processing.',
                    'Match packing and PO context to EPCIS event contents.',
                    'Quarantine guidance when data is incomplete.',
                ]"
            />
            <x-marketing.detail-section
                title="3T document intake"
                :items="[
                    'Transaction information, history, and statement handling.',
                    'Missing 3T surfaced as structured exceptions with playbook text.',
                    'Linked to trading partners for correction follow-up.',
                ]"
            />
            <x-marketing.detail-section
                title="Product trace"
                :items="[
                    'Serial-level timeline across inbound aggregation and shipping events.',
                    'Investigation view for recalls, discrepancies, and audits.',
                    'EPCIS display formatting for operator readability.',
                ]"
            />
            <x-marketing.detail-section
                title="Expected shipments registry"
                :items="[
                    'Register inbound shipments before files arrive—so receiving clerks know what to expect on the dock.',
                    'Match EPCIS and scan results against registered PO and packing context.',
                    'Surface overdue or missing inbound files as structured exceptions.',
                ]"
            />
            <x-marketing.detail-section
                title="Inbound error investigation"
                :items="[
                    'Drill into validation and processing failures with operator-readable detail.',
                    'Link errors to trading partners for supplier correction follow-up.',
                    'Playbook guidance on common EPCIS and 3T mismatch patterns.',
                ]"
            />
            <x-marketing.detail-section
                title="Strict EPCIS profile enforcement"
                :items="[
                    'Reject non-conforming inbound files before they enter inventory workflows.',
                    'Compliance hold on shipments that fail profile validation rules.',
                    'Audit trail of rejected files and hold reasons for inspection prep.',
                ]"
            />
            <x-marketing.detail-section
                title="Receiving acceptance metrics"
                :items="[
                    'Dashboard for receipt pass rates, validation failures, and SLA trends.',
                    'Per-facility and per-partner breakdowns for receiving managers.',
                    'Trend visibility before exception volume spikes on the dock.',
                ]"
            />
            <x-marketing.detail-section
                title="Multi-site receiving"
                :items="[
                    'Facility-scoped receiving sessions and inventory—so multi-DC operators keep sites isolated.',
                    'Site-level metrics and exception routing for regional compliance leads.',
                    'Same L4 receiving engine across warehouse, 3PL, and dispenser profiles.',
                ]"
            />
        </div>
    </section>

    @include('marketing.partials.feature-related', ['current' => 'receiving'])
    <x-marketing.cta-banner title="Bring a sample inbound file" description="Request a demo—we'll show how TracePharma processes your EPCIS format and flags exceptions on receipt for wholesaler, manufacturer, or 3PL inbound." />
@endsection
