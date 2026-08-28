@php
    $infinitrakFaqs = [
        ['question' => 'Is InfiniTrak a good fit for independent pharmacies?', 'answer' => 'Yes, for many independents. InfiniTrak offers turnkey DSCSA onboarding, PMS partnerships, and guided wholesaler connectivity—ideal when you want minimal IT involvement and verify-focused workflows.'],
        ['question' => 'When should a pharmacy consider TracePharma instead?', 'answer' => 'Consider TracePharma when you need event-store investigation (EPCIS 1.2 GA; 2.0 capture + query-as-2.0), structured exception workflows, multi-site reporting, or buying-group infrastructure—not just dispense-time verification.'],
        ['question' => 'Can TracePharma connect to the same wholesalers as InfiniTrak?', 'answer' => 'TracePharma connects trading partners directly via AS2, SFTP, and HTTPS per tenant. Cutover typically involves re-pointing EPCIS delivery to your TracePharma endpoints after partner credential setup.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'InfiniTrak alternative — TracePharma')
@section('meta_description', 'Honest comparison of TracePharma vs InfiniTrak for US DSCSA pharmacy compliance. Turnkey dispenser onboarding vs EPCIS 1.2 GA / 2.0 capture, query-as-2.0, and HTTPS subscriptions, wholesaler workflows, and buying-group scale.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="TracePharma as an InfiniTrak alternative"
        description="InfiniTrak is a strong turnkey choice for independent pharmacies with PMS partnerships and white-glove onboarding. TracePharma fits dispensers and networks who need wholesaler-grade EPCIS depth, event-store investigation (1.2 GA; 2.0 capture + query-as-2.0), and profile-tuned L4 workflows beyond verify-only."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.compare.index') }}">Compare</a> / InfiniTrak alternative
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.compare.checklist') }}">Provider checklist →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                Pharmacy owners often hear "use InfiniTrak" from wholesalers or buying groups. That advice fits teams who want <strong class="text-tp-ink">guided dispenser cutover</strong> with minimal IT involvement. Teams look at TracePharma when they also run—or plan to run—<strong class="text-tp-ink">EPCIS receiving, exception investigation, and multi-site compliance reporting</strong> at wholesaler depth.
            </p>
            <p>
                This page is intentionally honest. InfiniTrak wins on pharmacy market familiarity and turnkey onboarding. TracePharma wins on EPCIS 1.2 GA with opt-in 2.0 repository (read-only event-store trace for dispensers), structured exceptions, buying-group dashboards, and a single L4 platform if you outgrow verify-only workflows.
            </p>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Feature comparison</h2>
            <p class="mt-3 max-w-2xl text-sm text-tp-muted">Dispenser onboarding and verify workflows overlap. Wholesaler-grade EPCIS depth and multi-profile L4 are where platforms diverge.</p>

            <x-marketing.competitor-table
                class="mt-8"
                competitor-label="InfiniTrak"
                :rows="[
                    ['capability' => 'Pharmacy onboarding', 'them' => 'Strong — white-glove (InfiniTrak publishes dispenser counts)', 'us' => 'Self-serve cutover + persona UX after demo provisioning'],
                    ['capability' => 'PMS integrations', 'them' => 'Strong — RedSail and pharmacy ecosystem', 'us' => 'POST /api/v1/dispense-check (named adapters not GA)'],
                    ['capability' => 'VRS verification', 'them' => 'Yes — pharmacy-focused', 'us' => 'Yes — workstation + API, audit trail'],
                    ['capability' => 'EPCIS receiving', 'them' => 'Present — pharmacy scope', 'us' => 'Strong — scan-first, 3T matching, wholesaler depth'],
                    ['capability' => 'EPCIS 1.2 / 2.0 repository', 'them' => 'Limited public positioning', 'us' => '1.2 GA default outbound; 2.0 JSON-LD capture + query-as-2.0 + HTTPS subscriptions; read-only trace for pharmacy'],
                    ['capability' => 'Exception investigation', 'them' => 'Basic workflows', 'us' => 'Structured exceptions + supplier correction loop'],
                    ['capability' => 'Wholesaler / 3PL depth', 'them' => 'Partial L4 — not primary ICP', 'us' => 'Full profiles for wholesaler, 3PL, manufacturer'],
                    ['capability' => 'Buying group reporting', 'them' => 'Per-member tools', 'us' => 'Network dashboard + partner authorization matrix'],
                    ['capability' => 'Partner connectivity', 'them' => 'Assisted wholesaler onboarding', 'us' => 'Direct AS2, SFTP, HTTPS per tenant'],
                    ['capability' => 'Typical buyer', 'them' => 'Independent pharmacies', 'us' => 'Pharmacies, buying groups, regional wholesalers'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose InfiniTrak when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want turnkey pharmacy onboarding with minimal IT staff—so go-live does not depend on internal integration projects</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Your wholesaler or PMS vendor already standardizes on InfiniTrak—so you stay on the path partners already support</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Verify-only workflows meet your compliance needs today—so you are not paying for DC-grade EPCIS you will not run</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You do not plan to operate secondary DC or buying-group infrastructure—so network visibility beyond the store is not a requirement</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TracePharma when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You need EPCIS event-store investigation and serial-level receiving audit trails—so inspectors see evidence, not email threads</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You operate or support multiple pharmacy sites, a buying group, or a secondary DC—so one platform covers store and network roles</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Wholesaler-grade exception workflows matter—not just verify-at-dispense—so missing 3T and stale ACKs get tracked to resolution</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want one L4 platform if the organization grows beyond dispenser-only scope—so you do not re-platform when you add a DC</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Related solutions</h2>
            <div class="mt-8 grid gap-4 md:grid-cols-2">
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pharmacy / dispenser</p>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">Receiving, VRS verification, FDA 3911, and POST /api/v1/dispense-check for independent and small-chain pharmacies.</p>
                    <a href="{{ route('marketing.solutions.pharmacies') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Pharmacy solution →</a>
                </div>
                <div class="tp-card p-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Buying group</p>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">Member health dashboards, exception trends, and authorized partner matrix across your network.</p>
                    <a href="{{ route('marketing.solutions.buying-groups') }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Buying group solution →</a>
                </div>
            </div>
        </div>
    </section>

    <x-marketing.faq :items="$infinitrakFaqs" title="InfiniTrak vs TracePharma FAQ" />

    <x-marketing.cta-banner
        title="Evaluating a switch from InfiniTrak?"
        description="Tell us your site count, PMS, and wholesaler partners. Request a demo—we'll show the workflows that matter and map an honest fit assessment."
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
            ], $infinitrakFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
