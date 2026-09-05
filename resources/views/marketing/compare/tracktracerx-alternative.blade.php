@php
    $tracktracerxFaqs = [
        ['question' => 'Is TrackTraceRx a good fit for pharmacy networks?', 'answer' => 'Yes, when you need rapid dispenser rollout with partner location directory scale and onboarding economics. TrackTraceRx emphasizes network enrollment and pharmacy-first workflows.'],
        ['question' => 'When should a pharmacy network choose TracePharma over TrackTraceRx?', 'answer' => 'Consider TracePharma when you need event-store investigation (EPCIS 1.2 GA; 2.0 capture + query-as-2.0), structured exceptions, buying-group control-plane visibility (partner ATP readiness / alert center), or wholesaler-grade receiving—not just verify-only network enrollment.'],
        ['question' => 'Does TracePharma interoperate with TrackTraceRx paths?', 'answer' => 'Yes. TracePharma supports HTTPS webhook preset (tracktracerx) for inbound scenarios while you run full L4 workflows on TracePharma.'],
    ];
@endphp

@extends('marketing.layout')

@section('title', 'TrackTraceRx alternative — TracePharma')
@section('meta_description', 'Honest comparison of TracePharma vs TrackTraceRx for pharmacy DSCSA. Network onboarding scale vs EPCIS 1.2 GA / 2.0 capture, query-as-2.0, and HTTPS subscriptions and buying-group infrastructure.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="TracePharma as a TrackTraceRx alternative"
        description="TrackTraceRx targets pharmacy networks with partner location scale and fast onboarding. TracePharma fits when you need HTTPS interoperability plus wholesaler-grade EPCIS receiving, investigation tools, and multi-profile L4 workflows."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.compare.index') }}">Compare</a> / TrackTraceRx alternative
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            <a href="{{ route('marketing.integrations.show', 'tracktracerx') }}">TrackTraceRx interoperability →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            <p>
                TrackTraceRx (TrackRX / RapidRX) is a Pulse-listed pharmacy platform emphasizing partner location directories and onboarding economics. TracePharma fits networks that outgrow verify-only enrollment and need <strong class="text-tp-ink">serial-level receiving audit trails, exception investigation, and buying-group control-plane visibility</strong> on one L4 hub.
            </p>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Feature comparison</h2>
            <p class="mt-3 max-w-2xl text-sm text-tp-muted">Pharmacy network enrollment and verify workflows overlap. EPCIS repository depth (1.2 GA; 2.0 capture + query-as-2.0) and buying-group infrastructure are where platforms diverge.</p>

            <x-marketing.competitor-table
                class="mt-8"
                competitor-label="TrackTraceRx"
                :rows="[
                    ['capability' => 'Partner network onboarding', 'them' => 'Strong — TrackTraceRx publishes large location directories', 'us' => 'Direct partner connectivity + onboarding wizard'],
                    ['capability' => 'Pharmacy workflows', 'them' => 'Strong — network rollout focus', 'us' => 'Pharmacy profile + POST /api/v1/dispense-check'],
                    ['capability' => 'VRS verification', 'them' => 'Yes', 'us' => 'Yes — workstation + API audit trail'],
                    ['capability' => 'EPCIS 1.2 / 2.0 repository', 'them' => 'Limited public detail', 'us' => '1.2 GA default outbound; 2.0 JSON-LD capture + query-as-2.0 + HTTPS subscriptions; read-only trace for pharmacy'],
                    ['capability' => 'Buying group reporting', 'them' => 'Limited', 'us' => 'Network dashboard + partner authorization matrix'],
                    ['capability' => 'Wholesaler / manufacturer L4', 'them' => 'Thin', 'us' => 'Full multi-profile platform'],
                    ['capability' => 'Typical buyer', 'them' => 'Pharmacy networks, rapid deployment', 'us' => 'Networks needing L4 depth beyond enrollment'],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TrackTraceRx when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Network enrollment speed and partner directory scale are the priority—so new members connect before DSCSA deadlines</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Verify-focused workflows meet compliance needs today—so you are not funding wholesaler-grade investigation you will not use yet</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Free partner onboarding economics matter more than EPCIS investigation depth—so per-site license cost drives the decision</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Choose TracePharma when</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You need EPCIS event-store trace search and structured exception workflows—so serial history is one query, not a folder of PDFs</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Buying groups or multi-site ops need member health dashboards—so at-risk stores surface before a wholesaler escalation</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> You want one platform if the organization grows beyond pharmacy-only scope—so adding a DC does not mean a second vendor</li>
                </ul>
            </div>
        </div>
    </section>

    <x-marketing.faq :items="$tracktracerxFaqs" title="TrackTraceRx vs TracePharma FAQ" />

    <x-marketing.cta-banner
        title="Evaluating TrackTraceRx vs TracePharma?"
        description="Share your network size and wholesaler partners. Request a demo—we'll map HTTPS cutover and walk the workflows that matter."
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
            ], $tracktracerxFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
