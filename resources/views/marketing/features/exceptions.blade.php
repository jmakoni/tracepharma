@extends('marketing.layout')

@section('title', 'Exception workflows — TracePharma features')
@section('meta_description', 'DSCSA exception management, supplier correction loopback, partner risk scoring, and playbook guidance in TracePharma.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Feature deep dive"
        title="Exceptions & supplier accountability"
        description="DSCSA failures are operational events—not inbox noise. TracePharma assigns, tracks, and documents exceptions from first detection through supplier correction. Covers inbound receive issues and outbound ship problems."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.features') }}">Features</a> / Exceptions
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-marketing.detail-section
                title="Structured exception handling"
                :items="[
                    'Reason codes for missing 3T, serial mismatches, VRS failures, ACK timeouts, and more—so every failure type has a defined next step.',
                    'Assignment, status, resolution notes, and audit history—so compliance leads see who owns each open issue.',
                    'In-app playbook guidance for common scenarios—so receiving clerks know whether to quarantine, call the supplier, or escalate.',
                ]"
            />
            <x-marketing.detail-section
                title="Supplier correction loop"
                :items="[
                    'Send correction requests to trading partners from the exception view.',
                    'Optional auto-send when new exceptions are created.',
                    'Tenant setting to control automatic supplier outreach.',
                ]"
            />
            <x-marketing.detail-section
                title="Partner risk scoring"
                :items="[
                    '30-day verification failure rate per trading partner.',
                    'Elevated risk badge when failure rate exceeds threshold.',
                    'Helps prioritize wholesaler conversations and file quality reviews.',
                ]"
            />
            <x-marketing.detail-section
                title="Notifications & webhooks"
                :items="[
                    'Email owners when new exceptions are created.',
                    'HTTPS webhooks for exception.created events to your middleware.',
                    'Pairs with verification webhooks for closed-loop integrations.',
                ]"
            />
        </div>
    </section>

    @include('marketing.partials.feature-related', ['current' => 'exceptions'])
    <x-marketing.cta-banner title="Walk through an exception end-to-end" description="Request a demo—from inbound mismatch or VRS failure to supplier correction, mapped to your escalation process." />
@endsection
