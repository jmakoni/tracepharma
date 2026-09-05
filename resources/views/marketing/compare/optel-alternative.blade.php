@php
    $optelFaqs = [
        ['question' => 'Is OPTEL VerifyBrand a TraceLink alternative?', 'answer' => 'Yes, for many global manufacturers. OPTEL competes on fixed-cost unlimited serials and GS1 EPCIS exchange—often attracting TraceLink refugees at renewal. TracePharma competes for US-only mid-market programs that do not need global L1–L5 hardware.'],
        ['question' => 'Does TracePharma replace OPTEL plant-floor serialization?', 'answer' => 'No. OPTEL can span L1–L5 including line equipment. TracePharma operates at US L4—corporate EPCIS hub, partner connectivity, and operator receiving/ship workflows.'],
        ['question' => 'When should a US manufacturer choose TracePharma over OPTEL?', 'answer' => 'When DSCSA is the primary frame, partner count is manageable without global hub modules, and you want self-serve SaaS cutover with scan-first operator UX—not a multi-year global serialization program.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'OPTEL alternative — TracePharma')
@section('meta_description', 'Honest comparison of TracePharma vs OPTEL VerifyBrand for US DSCSA manufacturers. Mid-market L4 SaaS vs global fixed-cost serialization platform.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="TracePharma as an OPTEL VerifyBrand alternative"
        description="OPTEL VerifyBrand serves global MAHs with fixed-cost unlimited serials and optional full L1–L5 stack. TracePharma fits US manufacturers and regional distributors who need outbound EPCIS, ACK monitoring, and L3 handoff—without funding global hub modules they will not use."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.compare.index') }}">Compare</a> / OPTEL alternative
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a manufacturer demo →</a>
            <a href="{{ route('marketing.integrations.show', 'optel') }}">OPTEL interoperability →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                Manufacturers evaluating OPTEL often compare fixed-fee economics to TraceLink per-serial models. TracePharma enters when the buyer's scope is <strong class="text-tp-ink">US DSCSA outbound and customer ACK health</strong>—not EU FMD country hubs, unlimited global serials, or full packaging-line hardware validation.
            </p>
            <p>
                OPTEL is a Pulse-listed interoperable partner. TracePharma connects to US trading partners via standard EPCIS transports (AS2, SFTP, HTTPS)—without requiring OPTEL as middleware for your L4 hub.
            </p>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Feature comparison</h2>
            <p class="mt-3 max-w-2xl text-sm text-tp-muted">US outbound EPCIS and ACK monitoring overlap. OPTEL's global L1–L5 hardware stack is where breadth diverges.</p>

            <x-marketing.competitor-table
                class="mt-8"
                competitor-label="OPTEL VerifyBrand"
                :rows="[
                    ['capability' => 'Global regulation', 'them' => 'DSCSA, EU FMD, global hubs', 'us' => 'US DSCSA only — deliberate scope'],
                    ['capability' => 'Pricing model', 'them' => 'Fixed-cost unlimited serials (published)', 'us' => 'Demo-scoped SaaS packaging'],
                    ['capability' => 'L1–L5 stack', 'them' => 'Optional full hardware + cloud', 'us' => 'L4 hub + L3 commissioning forward'],
                    ['capability' => 'EPCIS outbound & ACK', 'them' => 'Strong — enterprise programs', 'us' => 'Strong — mid-market operator UX'],
                    ['capability' => 'TraceLink migration', 'them' => 'Published switch guides + Jennason PS', 'us' => 'Direct partner re-connection via presets'],
                    ['capability' => 'Dispenser / pharmacy UX', 'them' => 'Not primary ICP', 'us' => 'Pharmacy profile + POST /api/v1/dispense-check'],
                    ['capability' => 'Typical buyer', 'them' => 'Global MAH, TraceLink refugees', 'us' => 'US SMB–mid-market manufacturers & distributors'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose OPTEL when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You need global serialization across multiple markets in one vendor—so plant, corporate hub, and country gateways stay on one contract</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Fixed-cost unlimited serials at enterprise volume is the economic driver—so per-serial fees do not scale with line output</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want optional L1–L5 hardware and validation from one supplier—so line equipment and cloud hub share one validation playbook</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TracePharma when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> US DSCSA outbound and customer ACK monitoring are the core program—so you are not funding EU FMD modules you will not operate</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You connect known US wholesalers directly without global hub fees—so EPCIS routes to your tenant on AS2 or SFTP you control</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want profile-tuned SaaS with scan-first workflows for warehouse staff—so supply chain and QA run daily ops without PS-only screens</li>
                </ul>
            </div>
        </div>
    </section>

    <x-marketing.faq :items="$optelFaqs" title="OPTEL vs TracePharma FAQ" />

    <x-marketing.cta-banner
        title="Comparing OPTEL, TraceLink, and TracePharma?"
        description="Share your markets, partner list, and plant-floor stack. Request a demo—we'll map an honest US L4 fit assessment."
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
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $optelFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
