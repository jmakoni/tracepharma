@extends('marketing.layout')

@section('title', 'VRS verification — TracePharma features')
@section('meta_description', 'DSCSA VRS verification with audit logs and dispense eligibility for pharmacy, wholesaler, and dental/medical dispenser profiles.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Feature deep dive"
        title="VRS verification & dispense eligibility"
        description="Every verification is logged with full context—GTIN, serial, lot, expiry, outcome, and timestamp. Operators and auditors see the same evidence. Available on dispenser and select distributor profiles; manufacturers and 3PLs focus on outbound traceability."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.features') }}">Features</a> / Verification
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-marketing.detail-section
                title="Verification workstation"
                :items="[
                    'Scan or enter GTIN, serial, lot, and expiry at a dedicated operator screen—so staff verify at a workstation built for pharmacy and DC roles.',
                    'VRS outcomes are stored with request and response metadata—so auditors review the same record operators used at verify.',
                    'Failed or inconclusive results can flow directly into exception workflows—so negative VRS does not stop at a pop-up message.',
                ]"
            />
            <x-marketing.detail-section
                title="API & automation"
                :items="[
                    'POST /api/v1/verify for POS, robotics, or middleware integrations.',
                    'Dispense-check endpoint gates dispensing on verification and quarantine state.',
                    'Tenant-scoped Sanctum tokens with permission controls.',
                ]"
            />
            <x-marketing.detail-section
                title="Manufacturer notification"
                :items="[
                    'Optional email to manufacturers when VRS returns a negative result.',
                    'Configured per tenant through VRS routing settings.',
                    'Tied to the originating verification and exception record.',
                ]"
            />
            <x-marketing.detail-section
                title="Reporting"
                :items="[
                    'Verification summary reports by date range for compliance packages.',
                    'Period statistics exported alongside exception artifacts.',
                    'Supports management review and inspection requests.',
                ]"
            />
        </div>
    </section>

    @include('marketing.partials.feature-related', ['current' => 'verification'])
    <x-marketing.cta-banner title="See verification in your workflow" description="Request a demo—we'll connect to your VRS endpoint and walk through failure scenarios at the workstation or via your PMS integration." />
@endsection
