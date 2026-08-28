@php
    $advasurFaqs = [
        ['question' => 'Is Advasur 360 a good fit for independent pharmacies?', 'answer' => 'Yes, when you want guided DSCSA cutover with partner onboarding as a service. Advasur excels at white-glove pharmacy and light-wholesaler onboarding similar to other turnkey dispenser platforms.'],
        ['question' => 'When should a pharmacy choose TracePharma over Advasur?', 'answer' => 'Consider TracePharma when you need event-store investigation (EPCIS 1.2 GA; 2.0 capture + query-as-2.0), structured exceptions, multi-site reporting, or buying-group infrastructure—and when SFTP EPCIS from partners can route to your TracePharma tenant via the advasur preset.'],
        ['question' => 'Can TracePharma receive Advasur-path EPCIS files?', 'answer' => 'Yes. TracePharma supports SFTP polling with the Advasur serialization provider preset when partners drop EPCIS shipment files to your tenant inbox.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'Advasur alternative — TracePharma')
@section('meta_description', 'Honest comparison of TracePharma vs Advasur 360 for US DSCSA pharmacy and light-wholesaler compliance. Guided onboarding vs full L4 EPCIS depth.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="TracePharma as an Advasur 360 alternative"
        description="Advasur 360 focuses on guided pharmacy and light-wholesaler cutover with partner onboarding services. TracePharma fits teams who need wholesaler-grade EPCIS receiving, event-store investigation (1.2 GA; 2.0 capture + query-as-2.0), and multi-profile L4 workflows when they outgrow dispenser-only tooling."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.compare.index') }}">Compare</a> / Advasur alternative
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.integrations.show', 'advasur') }}">Advasur interoperability →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                Advasur is a Pulse-listed platform with a strong reputation for partner onboarding services—especially for pharmacies evaluating DSCSA for the first time. TracePharma fits when you need the same <strong class="text-tp-ink">SFTP EPCIS interoperability</strong> path but want structured exceptions, event-store trace search (1.2 GA; 2.0 capture + query-as-2.0), and room to grow into wholesaler or buying-group profiles.
            </p>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Feature comparison</h2>
            <p class="mt-3 max-w-2xl text-sm text-tp-muted">Pharmacy cutover and SFTP EPCIS paths overlap. Wholesaler-grade L4 depth and buying-group reporting are where platforms diverge.</p>

            <x-marketing.competitor-table
                class="mt-8"
                competitor-label="Advasur 360"
                :rows="[
                    ['capability' => 'Partner onboarding service', 'them' => 'Strong — guided cutover', 'us' => 'Self-serve onboarding wizard + demo scoping'],
                    ['capability' => 'EPCIS receiving', 'them' => 'Yes — SFTP-focused', 'us' => 'Yes — SFTP, AS2, HTTPS presets'],
                    ['capability' => 'VRS verification', 'them' => 'Yes', 'us' => 'Yes — workstation + PMS APIs'],
                    ['capability' => 'EPCIS 1.2 / 2.0 repository', 'them' => 'Limited public detail', 'us' => '1.2 GA default outbound; 2.0 JSON-LD capture + query-as-2.0 + HTTPS subscriptions; read-only trace for pharmacy'],
                    ['capability' => 'Exception investigation', 'them' => 'Basic workflows', 'us' => 'Structured exceptions + supplier correction loop'],
                    ['capability' => 'Wholesaler / 3PL depth', 'them' => 'Light wholesaler only', 'us' => 'Full regional wholesaler and 3PL profiles'],
                    ['capability' => 'Buying group reporting', 'them' => 'Limited', 'us' => 'Network dashboard + partner authorization matrix'],
                    ['capability' => 'Typical buyer', 'them' => 'Pharmacies, small distributors', 'us' => 'Pharmacies scaling to multi-site or group ops'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose Advasur when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want onboarding-as-a-service with minimal internal IT—so cutover does not queue behind your help desk</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Dispenser-only verify and receive workflows are sufficient—so you are not funding DC workflows you will not operate</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Your buying group or wholesaler recommends Advasur specifically—so partner onboarding stays on a path they already support</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TracePharma when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You need EPCIS event-store investigation and serial-level audit trails—so trace search replaces spreadsheet reconstruction</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You operate or support multiple sites or a buying group—so member health is visible before an inspection call</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You may add wholesaler-grade workflows without switching vendors again—so growth to a secondary DC does not force re-platforming</li>
                </ul>
            </div>
        </div>
    </section>

    <x-marketing.faq :items="$advasurFaqs" title="Advasur vs TracePharma FAQ" />

    <x-marketing.cta-banner
        title="Migrating from Advasur 360?"
        description="Request a demo—we'll walk through SFTP preset setup, test receiving, and profile selection for your pharmacy or light-wholesaler operation."
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
            ], $advasurFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
