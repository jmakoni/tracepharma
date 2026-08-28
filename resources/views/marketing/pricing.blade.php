@php
    $pricingFaqs = [
        ['question' => 'Does TracePharma publish list prices?', 'answer' => 'No. TracePharma quotes after a demo and scoping call. Packaging depends on your operating profile, facility count, partner connections, and optional modules—not a generic per-seat tier table.'],
        ['question' => 'What is included in a typical subscription?', 'answer' => 'Core L4 DSCSA workflows for your profile: EPCIS ingest and outbound, 3T matching, exceptions, compliance reporting, and tenant-scoped partner connectivity. VRS, dispense-check API, and L3 commissioning forward are quoted when your profile needs them.'],
        ['question' => 'Do you offer annual and monthly billing?', 'answer' => 'Yes. Most customers choose annual packaging for predictable compliance budgeting. Monthly options are available for qualified deployments after scoping.'],
        ['question' => 'How long does implementation take?', 'answer' => 'Mid-market cutovers often run four to twelve weeks depending on partner count and transport channels. Self-serve onboarding in the app covers GLN setup, first partner, and test receiving after your tenant is provisioned.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'Pricing — TracePharma')
@section('meta_description', 'TracePharma pricing is scoped to your operating profile, sites, and partner connections. Request a demo for a custom proposal—no public tier table.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Pricing"
        title="Packaging scoped to your operation"
        description="We do not publish list prices. TracePharma is quoted after we understand your operating profile, facility count, trading partners, and optional modules—so you pay for the L4 workflows you actually run."
    >
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.compare.checklist') }}">Provider checklist →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                TracePharma serves manufacturers, wholesalers, 3PLs, prepackagers, buying groups, and dispensers from one multi-tenant platform. Pricing reflects <strong class="text-tp-ink">how you operate</strong>, not a one-size SaaS tier chart.
            </p>
            <p>
                Every proposal starts with a demo on a profile-tuned workspace. We map your partner list, inbound channels, and compliance reporting needs before quoting.
            </p>
        </div>

        <h2 class="mt-14 text-2xl font-semibold tracking-tight text-tp-ink">What drives your quote</h2>
        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Operating profile</p>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Manufacturer, wholesaler, 3PL, dispenser, prepackager, buying group, or dental/medical supply—each profile enables different workflows and nav.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Sites &amp; GLNs</p>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Facility count, ship-from and receive-at GLNs, and whether you run principal-scoped 3PL operations across locations.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Partner connections</p>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Inbound AS2, SFTP, HTTPS webhooks, and outbound EPCIS volume to wholesalers, customers, and dispensers.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Optional modules</p>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">VRS verification, POST /api/v1/dispense-check, L3 commissioning forward, compliance export API, and multi-principal 3PL reporting.</p>
            </div>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">How we quote</h2>
            <ol class="mt-8 grid gap-6 md:grid-cols-3">
                <li class="tp-card p-6">
                    <p class="text-sm font-semibold text-tp-teal-400">1. Demo</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">Walk through receiving, outbound ship, exceptions, or verification on a tenant tuned to your profile. Select your organization type when you <a href="{{ route('marketing.demo') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">request a demo</a>.</p>
                </li>
                <li class="tp-card p-6">
                    <p class="text-sm font-semibold text-tp-teal-400">2. Scoping</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">We document trading partners, transport channels, site count, and go-live timeline. No surprise module upsells—you see the workflows before you sign.</p>
                </li>
                <li class="tp-card p-6">
                    <p class="text-sm font-semibold text-tp-teal-400">3. Custom proposal</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">Annual or monthly packaging with implementation support aligned to your cutover plan. Compare us using our <a href="{{ route('marketing.compare.checklist') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">provider checklist</a>.</p>
                </li>
            </ol>
        </div>
    </section>

    <x-marketing.faq :items="$pricingFaqs" />

    <x-marketing.cta-banner
        title="Ready for a scoped quote?"
        description="Request a demo with your operating profile selected. We walk through your workflows first, then send packaging options—no surprise module upsells."
    />
@endsection

@push('head')
    @php
        $faqSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ], $pricingFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
