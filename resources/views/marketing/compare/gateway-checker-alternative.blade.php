@php
    $gatewayFaqs = [
        ['question' => 'Is Gateway Checker a full L4 traceability platform?', 'answer' => 'Gateway Checker is primarily a regional connectivity hub for HTTPS EPCIS exchange. TracePharma is a full L4 workspace—receiving, outbound ship, exceptions, VRS, and compliance reporting—with Gateway Checker interoperability via HTTPS preset.'],
        ['question' => 'Can I migrate from Gateway Checker to TracePharma?', 'answer' => 'Yes. Partners re-point EPCIS delivery to your TracePharma inbound webhook using the gateway_checker preset. Cutover focuses on credential exchange and test shipments—not changing the EPCIS standard.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'Gateway Checker alternative — TracePharma')
@section('meta_description', 'Compare TracePharma vs Gateway Checker for regional wholesaler DSCSA. Full L4 operator workflows vs connectivity-hub economics.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="TracePharma as a Gateway Checker alternative"
        description="Gateway Checker is a Pulse-listed connectivity vendor familiar to regional wholesalers and specialty distributors. TracePharma interoperates via HTTPS—and adds receive-to-ship operator UX, exceptions, ACK monitoring, and compliance exports beyond document exchange alone."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.compare.index') }}">Compare</a> / Gateway Checker alternative
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.integrations.show', 'gateway-checker') }}">Gateway Checker interoperability →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                Regional distributors often inherit Gateway Checker relationships from partner location registries. That works when HTTPS EPCIS exchange is the primary need. TracePharma fits when your DC team needs <strong class="text-tp-ink">scan-first receiving, exception investigation, outbound ship, and ACK health dashboards</strong>—not just a connectivity endpoint.
            </p>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Feature comparison</h2>
            <p class="mt-3 max-w-2xl text-sm text-tp-muted">HTTPS EPCIS exchange overlaps. Receive-to-ship operator workflows, exceptions, and compliance exports are where platforms diverge.</p>

            <x-marketing.competitor-table
                class="mt-8"
                competitor-label="Gateway Checker"
                :rows="[
                    ['capability' => 'HTTPS EPCIS exchange', 'them' => 'Strong — regional incumbent', 'us' => 'Strong — gateway_checker preset + full L4'],
                    ['capability' => 'Scan-first receiving', 'them' => 'Limited operator UX', 'us' => 'Core workflow — warehouse personas'],
                    ['capability' => 'Outbound ship & SSCC', 'them' => 'Varies by deployment', 'us' => 'Full outbound generation + labels'],
                    ['capability' => 'Exception investigation', 'them' => 'Basic', 'us' => 'Structured exceptions + supplier loop'],
                    ['capability' => 'ACK monitoring', 'them' => 'Limited', 'us' => 'Per-partner ACK health dashboards'],
                    ['capability' => 'Compliance exports', 'them' => 'Document-focused', 'us' => 'FDA 3911, audit packages, scorecards'],
                    ['capability' => 'Typical buyer', 'them' => 'Regional wholesalers, specialty dist', 'us' => 'Regional wholesalers needing operator L4'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose Gateway Checker when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Partners already standardize on Gateway Checker HTTPS paths—so re-pointing transport is not worth the cutover project yet</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Connectivity-only scope is sufficient short-term—so your team is not ready to run full DC receiving and ship in one hub</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TracePharma when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> DC staff need receive-to-ship workflows, not just file exchange—so receipts, exceptions, and outbound ship live in one operator workspace</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want one L4 hub for exceptions, VRS, and compliance reporting—so inspection prep pulls from operational data, not email</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You can re-point partners to TracePharma while keeping HTTPS transport—so cutover changes endpoints, not the EPCIS standard</li>
                </ul>
            </div>
        </div>
        <a href="{{ route('marketing.solutions.wholesalers') }}" class="mt-8 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Wholesaler solution →</a>
    </section>

    <x-marketing.faq :items="$gatewayFaqs" title="Gateway Checker vs TracePharma FAQ" />

    <x-marketing.cta-banner
        title="Regional wholesaler on Gateway Checker today?"
        description="Request a demo—we'll walk through HTTPS preset cutover plus receive-to-ship workflows on a wholesaler-profile tenant."
    />

    <x-marketing.checklist-sticky-bar />
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
            ], $gatewayFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
