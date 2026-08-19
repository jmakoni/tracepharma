@php
    $lspediaFaqs = [
        ['question' => 'Is TracePharma a full replacement for LSPedia OneScan?', 'answer' => 'For US DSCSA L4 workflows—EPCIS ingest, outbound, exceptions, VRS, and compliance—TracePharma covers the core overlap. LSPedia remains stronger for global regulation modules, Exchange network scale, and enterprise analytics products like OneData.'],
        ['question' => 'Who should choose TracePharma over LSPedia?', 'answer' => 'SMB–mid-market US trading partners who want operator-first receiving and ship workflows, direct partner connectivity without exchange fees, and profile-tuned SaaS—not fifteen global regulation modules they will not use.'],
        ['question' => 'Does TracePharma support EPCIS 2.0 like LSPedia?', 'answer' => 'Yes. TracePharma ships profile-gated EPCIS 2.0—full CBV 2.0 repository with queries and subscriptions for manufacturer, wholesaler, 3PL, and prepackager profiles; pharmacy tenants get a read-only repository for IT trace queries.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'LSPedia alternative — TracePharma')
@section('meta_description', 'Honest comparison of TracePharma vs LSPedia OneScan for US DSCSA L4 traceability. Who each platform fits, feature overlap, and when to choose direct partner connectivity over Exchange network scale.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="TracePharma as an LSPedia OneScan alternative"
        description="LSPedia is a mature, full-stack global traceability platform. TracePharma is a focused L4 DSCSA hub for US supply-chain participants who need operator UX, direct partner connectivity, and SaaS time-to-value—not global regulation modules they will not use."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.compare.index') }}">Compare</a> / LSPedia alternative
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.compare.checklist') }}">Provider checklist →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                Teams evaluate LSPedia when they need a proven DSCSA suite with broad module coverage and network-scale partner exchange. Teams look at TracePharma when they want the same <strong class="text-tp-ink">EPCIS-native L4 core</strong>—receive, ship, exceptions, compliance.
            </p>
            <p>
                TracePharma fits when you do not want to fund global hubs, financial rev-cycle modules, or consultant-heavy configuration your team will not operate.
            </p>
            <p>
                This page is intentionally honest. LSPedia wins on global regulation breadth and Exchange scale. TracePharma wins on operator UX, EPCIS 2.0 depth, profile-tuned SaaS, and lower TCO for SMB–mid-market manufacturers, wholesalers, 3PLs, and dispensers.
            </p>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Feature comparison</h2>
            <p class="mt-3 max-w-2xl text-sm text-tp-muted">Core US DSCSA workflows overlap on both platforms. LSPedia's global module catalog and Exchange network are where breadth diverges.</p>

            <x-marketing.competitor-table
                class="mt-8"
                competitor-label="LSPedia OneScan"
                :rows="[
                    ['capability' => 'EPCIS ingest & outbound', 'them' => 'Strong — full serialization suite', 'us' => 'Strong — unified L4 workspace'],
                    ['capability' => 'EPCIS 2.0 repository', 'them' => 'Yes', 'us' => 'Profile-gated — full CBV 2.0 repo (mfg/wholesale/3PL/prepack); read-only trace for pharmacy'],
                    ['capability' => 'VRS verification', 'them' => 'Yes — Pharmacy Pro SKU', 'us' => 'Yes — profile-gated dispenser/distributor'],
                    ['capability' => 'Exception investigation', 'them' => 'Investigator module', 'us' => 'Structured exceptions + supplier correction loop'],
                    ['capability' => 'Partner connectivity', 'them' => 'Exchange network (LSPedia publishes large connection counts)', 'us' => 'Direct AS2, SFTP, HTTPS per tenant'],
                    ['capability' => 'L3 serial provisioning', 'them' => 'Via partners / modules', 'us' => 'Built-in SGTIN allocation + commissioning reconcile'],
                    ['capability' => '3PL principal ops', 'them' => 'Edge / enterprise logistics', 'us' => 'Principal-scoped receive, cross-dock, lot-level ship'],
                    ['capability' => 'Global regulation (EU FMD, L5 hubs)', 'them' => 'Core strength', 'us' => 'US DSCSA only — deliberate scope'],
                    ['capability' => 'Enterprise analytics (OneData/Vault)', 'them' => 'Dedicated data platform', 'us' => 'Scorecards + compliance export API'],
                    ['capability' => 'Implementation model', 'them' => 'Often consultant-configured', 'us' => 'Self-serve cutover + persona UX'],
                    ['capability' => 'Typical buyer', 'them' => 'Enterprise + pharmacy chains', 'us' => 'SMB–mid-market US trading partners'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose LSPedia when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You need EU FMD, country hubs, or multi-market regulation in one vendor—so you avoid stitching regional hubs and corporate reporting separately</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Exchange network onboarding at scale is worth network fees—so unknown partners connect without manual credential projects per site</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want OneData/Vault-style enterprise analytics and data products—so compliance and finance share one governed dataset</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Pharmacy chain deployments need Pharmacy Pro depth across hundreds of sites—so rollout stays on one vendor playbook</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TracePharma when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> US DSCSA is your primary regulatory frame—so you do not pay for global modules your team never turns on</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You connect known partners directly—so EPCIS routes to your tenant without mandatory exchange middleman fees</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Floor staff need scan-first receiving and ship workflows—so receiving clerks finish receipts without consultant-only dashboards</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want profile-tuned tenancy (manufacturer, wholesaler, 3PL, dispenser) in one SaaS product—so one org can grow roles without new SKUs</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">By operating profile</h2>
            <div class="mt-8 grid gap-4 md:grid-cols-2">
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Drug manufacturer</p>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">LSPedia competes on full global MAH programs. TracePharma fits US outbound EPCIS, L3 handoff, and customer ACK health without TraceLink-scale network fees.</p>
                    <a href="{{ route('marketing.solutions.manufacturers') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Manufacturer solution →</a>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Drug wholesaler</p>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">LSPedia offers full DSCSA suite breadth. TracePharma emphasizes receive-to-ship operator UX, EPCIS 2.0 depth, and ACK monitoring at lower TCO.</p>
                    <a href="{{ route('marketing.solutions.wholesalers') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Wholesaler solution →</a>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">3PL / logistics</p>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">LSPedia Edge targets enterprise principal management. TracePharma delivers principal isolation, cross-dock audit, and lot-level ship for mid-market 3PLs.</p>
                    <a href="{{ route('marketing.solutions.3pl') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">3PL solution →</a>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pharmacy / dispenser</p>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">LSPedia Pharmacy Pro and network visibility serve chains well. TracePharma fits independents needing wholesaler-grade EPCIS depth and PMS dispense-check APIs.</p>
                    <a href="{{ route('marketing.solutions.pharmacies') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Pharmacy solution →</a>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.l4-stack :compact="true" />
        <p class="mt-6 max-w-3xl text-sm leading-relaxed text-tp-muted">
            TracePharma operates at <strong class="text-tp-ink">L4</strong>—your corporate EPCIS hub. We integrate with L3 plant systems and connect to trading partners; we do not operate national regulatory hubs (L5) or EU FMD country gateways.
        </p>
    </section>

    <x-marketing.cta-banner
        title="Evaluating a switch from LSPedia or a parallel pilot?"
        description="Share your operating profile, partner list, and must-have modules. Request a demo—we'll map an honest fit assessment and walk the workflows that matter."
    />

    <x-marketing.faq :items="$lspediaFaqs" title="LSPedia vs TracePharma FAQ" />

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
            ], $lspediaFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
