@extends('marketing.layout')

@section('title', 'Compliance reporting — TracePharma features')
@section('meta_description', 'FDA Form 3911, verification summaries, operations scorecards, license validation, and compliance exports for all DSCSA trading partner types.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Feature deep dive"
        title="Compliance reporting & governance"
        description="Inspection-ready artifacts generated from live operational data. Whether you ship serialized product, receive-to-ship as a wholesaler, or verify at dispense, compliance exports pull from the same event history operators use daily."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.features') }}">Features</a> / Compliance
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-marketing.detail-section
                title="FDA Form 3911"
                :items="[
                    'Prefill suspected illegitimate product reports from verification exceptions—so 3911 drafts start from operational data, not blank forms.',
                    'Capture notifier, product, and incident details in-app—so staff complete reports without re-keying verify context.',
                    'Export PDF draft for FDA CDER NextGen 3911 submission—so compliance packages include a submission-ready artifact.',
                ]"
            />
            <x-marketing.detail-section
                title="Verification summaries"
                :items="[
                    'Period-based statistics: attempts, successes, failures, and rates.',
                    'Filament report page for management and compliance review.',
                    'Included in compliance package exports.',
                ]"
            />
            <x-marketing.detail-section
                title="Partner license validation"
                :items="[
                    'Scheduled license checks on trading partners.',
                    'Manual review driver or MedPro API integration.',
                    'License status visible on partner records.',
                ]"
            />
            <x-marketing.detail-section
                title="Operations scorecards"
                :items="[
                    'Manufacturer and wholesaler scorecards with ACK health and blocked-reason trends.',
                    '3PL principal-scoped operations view for multi-brand warehouses.',
                    'Dispenser scorecard with PMS blocked-reason trends for pharmacy profiles.',
                ]"
            />
            <x-marketing.detail-section
                title="Operational awareness"
                :items="[
                    'Drug shortage notices from central feed on tenant dashboard.',
                    'Onboarding PM checklist for platform admins at go-live.',
                    'Compliance package bundles verification and exception evidence.',
                ]"
            />
            <x-marketing.detail-section
                title="Compliance case management"
                :items="[
                    'Open and track compliance cases linked to serials, partners, and events.',
                    'Assignment, status, and resolution notes for regulatory follow-up.',
                    'Case evidence bundled into compliance package exports.',
                ]"
            />
            <x-marketing.detail-section
                title="TI/TS compliance dashboard"
                :items="[
                    'Transaction information and statement coverage across inbound and outbound.',
                    'Gap trends per trading partner for proactive supplier outreach.',
                    'Profile-tuned views for manufacturer, wholesaler, and 3PL compliance leads.',
                ]"
            />
            <x-marketing.detail-section
                title="Tracing requests & SLA"
                :items="[
                    'Log and track DSCSA tracing requests with response deadlines.',
                    'SLA dashboard for open, overdue, and completed tracing cases.',
                    'Linked serial history and partner context for faster investigation.',
                ]"
            />
            <x-marketing.detail-section
                title="Recall management"
                :items="[
                    'Recall case workflows with affected serial and lot scope.',
                    'Recall broadcasts to trading partners with delivery tracking.',
                    'Investigation views tied to inbound and outbound event history.',
                ]"
            />
            <x-marketing.detail-section
                title="Analytics workspace"
                :items="[
                    'Self-serve compliance and operations analytics beyond fixed scorecards.',
                    'Export-ready views for management review and inspection prep.',
                    'Profile-gated metrics for manufacturer, wholesaler, 3PL, and dispenser tenants.',
                ]"
            />
            <x-marketing.detail-section
                title="Compliance notification rules"
                :items="[
                    'Configurable alerts for verification failures, stale ACKs, and license lapses.',
                    'Route notifications to compliance leads and integration contacts.',
                    'Reduce surprise findings during audits with proactive awareness.',
                ]"
            />
        </div>
    </section>

    @include('marketing.partials.feature-related', ['current' => 'compliance'])
    <x-marketing.cta-banner title="See compliance exports live" description="Request a demo—we'll generate a sample verification summary and 3911 draft from a test exception." />
@endsection
