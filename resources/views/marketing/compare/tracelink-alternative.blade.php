@php
    $tracelinkFaqs = [
        ['question' => 'Can TracePharma replace TraceLink Opus entirely?', 'answer' => 'For US DSCSA L4 workflows—EPCIS receive, outbound, exceptions, VRS, and compliance—TracePharma can be your primary hub. Opus remains relevant when you need global regulation modules, automated onboarding of thousands of unknown partners, or you are already deeply embedded in Opus network economics.'],
        ['question' => 'Do I lose partner connectivity if I leave Opus?', 'answer' => 'Known partners can re-point EPCIS to TracePharma via AS2 or SFTP using the tracelink preset. The hard part is re-connection and credential exchange—not the EPCIS standard itself.'],
        ['question' => 'Does TracePharma interoperate with TraceLink-connected partners?', 'answer' => 'Yes. TracePharma receives and sends standards-based EPCIS to TraceLink endpoints your partners configure, without requiring Opus network enrollment fees on your tenant.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'TraceLink alternative — TracePharma')
@section('meta_description', 'Honest comparison of TracePharma vs TraceLink Opus for US DSCSA L4 traceability. Mid-market operator UX and direct partner connectivity vs global network scale.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="TracePharma as a TraceLink Opus alternative"
        description="TraceLink Opus is the incumbent network for global manufacturers and mega-wholesalers. TracePharma fits US trading partners who need scan-first L4 workflows, direct AS2/SFTP connectivity, and predictable SaaS economics—not Opus network transaction fees for a manageable partner list."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.compare.index') }}">Compare</a> / TraceLink alternative
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.integrations.show', 'tracelink') }}">TraceLink interoperability →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                Buyers often assume TraceLink is mandatory because a wholesaler or manufacturer mentioned Opus. That fits enterprises onboarding thousands of partners across global markets. Teams evaluate TracePharma when they have a <strong class="text-tp-ink">known partner list</strong>, US DSCSA is the primary frame, and floor operators—not consultants—run receiving and ship daily.
            </p>
            <p>
                This page is intentionally honest. TraceLink wins on network scale and global regulation breadth. TracePharma wins on operator UX, EPCIS 1.2 GA with 2.0 capture + query-as-2.0 + HTTPS subscriptions, mid-market TCO, and direct partner control without per-transaction network economics.
            </p>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Feature comparison</h2>
            <p class="mt-3 max-w-2xl text-sm text-tp-muted">Core US DSCSA workflows overlap on both platforms. Opus global regulation modules and network economics are where breadth diverges.</p>

            <x-marketing.competitor-table
                class="mt-8"
                competitor-label="TraceLink Opus"
                :rows="[
                    ['capability' => 'Partner network', 'them' => 'Large published network footprint — automated onboarding at scale', 'us' => 'Direct AS2, SFTP, HTTPS per tenant — no mandatory exchange'],
                    ['capability' => 'EPCIS ingest & outbound', 'them' => 'Strong — network-native messaging', 'us' => 'Strong — unified L4 workspace; 1.2 GA + 2.0 capture + query-as-2.0 + HTTPS subscriptions'],
                    ['capability' => 'Global regulation', 'them' => 'Multi-country programs — core strength', 'us' => 'US DSCSA only — deliberate scope'],
                    ['capability' => 'VRS verification', 'them' => 'Yes', 'us' => 'Yes — profile-gated dispenser/distributor'],
                    ['capability' => 'Exception investigation', 'them' => 'Enterprise workflows', 'us' => 'Structured exceptions + supplier correction loop'],
                    ['capability' => 'Operator UX', 'them' => 'Dashboard-heavy; PS-configured', 'us' => 'Scan-first receiving; persona-based self-serve cutover'],
                    ['capability' => 'Pricing model', 'them' => 'Quote + network/module fees; highest mid-market TCO', 'us' => 'Demo-scoped SaaS packaging'],
                    ['capability' => 'Implementation', 'them' => 'Months-long network onboarding common', 'us' => 'Weeks for mid-market known-partner cutover'],
                    ['capability' => 'Typical buyer', 'them' => 'Top-20 pharma, national wholesalers', 'us' => 'SMB–mid-market US trading partners'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TraceLink when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You need automated onboarding of thousands of partners on Opus network—so new ship-tos connect without per-partner IT projects</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> EU FMD and multi-country reporting must live in one incumbent vendor—so corporate compliance owns one global program</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Enterprise PS budget and timeline for network-scale programs—so rollout risk sits with the vendor's implementation playbook</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You are already embedded in Opus economics and renewal is cheaper than migration—so switching cost outweighs TCO gains</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TracePharma when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> US DSCSA is your primary regulatory frame—so you avoid global module fees your team will not operate</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You connect known partners directly via AS2 or SFTP—so EPCIS lands in your tenant without network transaction fees</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Warehouse and receiving staff need scan-first workflows—so floor operators close receipts without waiting on PS tickets</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want profile-tuned SaaS without Opus network transaction fees—so predictable packaging matches a manageable partner list</li>
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
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">TraceLink competes on global MAH network scale. TracePharma fits US outbound EPCIS, L3 handoff, and customer ACK health at lower TCO.</p>
                    <a href="{{ route('marketing.solutions.manufacturers') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Manufacturer solution →</a>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Drug wholesaler</p>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">TraceLink serves mega-wholesalers on Opus. TracePharma emphasizes receive-to-ship operator UX and ACK monitoring for regional distributors.</p>
                    <a href="{{ route('marketing.solutions.wholesalers') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Wholesaler solution →</a>
                </div>
            </div>
        </div>
    </section>

    <x-marketing.faq :items="$tracelinkFaqs" title="TraceLink vs TracePharma FAQ" />

    <x-marketing.cta-banner
        title="Evaluating a move off Opus or a parallel pilot?"
        description="Share your partner list and transport paths. Request a demo—we'll map an honest fit assessment and walk TraceLink preset cutover on your profile."
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
            ], $tracelinkFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
