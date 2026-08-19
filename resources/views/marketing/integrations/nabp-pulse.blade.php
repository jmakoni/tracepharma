@extends('marketing.layout')

@section('title', 'NABP Pulse & TracePharma — interoperability status')
@section('meta_description', 'How TracePharma relates to NABP Pulse interoperable partners—EPCIS-layer connectivity today and Pulse certification roadmap.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Integrations · NABP Pulse"
        title="TracePharma and the Pulse interoperable partner ecosystem"
        description="NABP Pulse connects regulators and authorized trading partners. Many DSCSA solution providers integrate with Pulse. TracePharma interoperates with those vendors at the EPCIS transport layer while Pulse directory API certification is on our roadmap."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.integrations.index') }}">Integrations</a> / NABP Pulse
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="https://pulse.pharmacy/solutions/pulse-interoperable-partners/" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200" rel="noopener noreferrer">Pulse partner program →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">What Pulse provides</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> ATP profile, license verification, and trading partner directory</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Regulator trace requests and interoperability communications</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> API integrations with 15+ listed solution providers</li>
                </ul>
            </div>
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">TracePharma today</h2>
                <ul class="mt-5 space-y-3 text-sm leading-relaxed text-tp-muted">
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> <strong class="text-tp-ink">Not yet listed</strong> as a Pulse-certified interoperable partner</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Interoperates with Pulse-listed vendors via AS2, SFTP, HTTPS EPCIS presets</li>
                    <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Pulse directory API integration is on the product roadmap</li>
                </ul>
            </div>
        </div>

        <div class="mt-14 tp-card border border-amber-500/20 p-8">
            <p class="text-xs font-semibold uppercase tracking-wide text-tp-warning">Roadmap · not yet certified</p>
            <h2 class="mt-3 text-xl font-semibold text-tp-ink">Pulse API certification roadmap</h2>
            <p class="mt-4 max-w-3xl text-sm leading-relaxed text-tp-muted">
                TracePharma is <strong class="text-tp-ink">not yet certified</strong> as a Pulse interoperable partner. We do not publish a go-live date. Customers can run L4 EPCIS operations today via direct partner transports while we complete Pulse API certification work.
            </p>
            <h3 class="mt-8 text-sm font-semibold uppercase tracking-wide text-tp-muted">Planned capabilities when certified</h3>
            <ul class="mt-4 space-y-3 text-sm leading-relaxed text-tp-muted">
                <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Pulse trading partner directory lookup from tenant partner authorization workflows</li>
                <li class="flex gap-3"><span class="text-tp-teal-400">→</span> ATP license verification against Pulse-sourced credentials before inbound EPCIS acceptance</li>
                <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Regulator trace request intake and status sync where Pulse APIs support interoperability communications</li>
                <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Optional Pulse-listed vendor handoff metadata on outbound EPCIS for partners expecting directory-backed routing</li>
                <li class="flex gap-3"><span class="text-tp-teal-400">→</span> Tenant-visible Pulse connection health on Integration Health (planned—mirrors existing AS2/SFTP monitors)</li>
            </ul>
            <p class="mt-6 text-sm text-tp-muted">
                Until certification ships, use
                <a href="{{ route('marketing.integrations.wholesale.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">wholesaler presets</a>
                and
                <a href="{{ route('marketing.integrations.show', 'tracelink') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Pulse-listed vendor pages</a>
                for EPCIS-layer cutover today.
            </p>
        </div>

        <h2 class="mt-14 text-xl font-semibold text-tp-ink">Pulse-listed vendors with TracePharma pages</h2>
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['TraceLink', 'tracelink'],
                ['LSPedia', 'lspedia'],
                ['InfiniTrak', 'infinitrak'],
                ['Advasur', 'advasur'],
                ['Gateway Checker', 'gateway-checker'],
                ['UniTrace (Systech)', 'unitrace'],
                ['Axway', 'axway'],
                ['OPTEL', 'optel'],
                ['SAP ATTP', 'sap'],
                ['rfXcel', 'rfxcel'],
                ['TrackTraceRx', 'tracktracerx'],
            ] as [$label, $slug])
                @if ($slug)
                    <a href="{{ route('marketing.integrations.show', $slug) }}" class="tp-card px-4 py-3 text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">{{ $label }} →</a>
                @else
                    <a href="{{ route('marketing.compare.optel') }}" class="tp-card px-4 py-3 text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">{{ $label }} (compare) →</a>
                @endif
            @endforeach
        </div>
        <p class="mt-6 text-sm text-tp-muted">
            Also see
            <a href="{{ route('marketing.integrations.pms.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">PMS</a>,
            <a href="{{ route('marketing.integrations.wholesale.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">wholesaler</a>, and
            <a href="{{ route('marketing.compare.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">compare</a> pages.
        </p>
    </section>

    @php
        $pulseFaqs = [
            ['question' => 'Must I use a Pulse interoperable partner for DSCSA?', 'answer' => 'Pulse simplifies ATP directory and regulator communications for many trading partners. DSCSA compliance itself is met through interoperable EPCIS exchange with authorized trading partners—whether or not you use Pulse directly.'],
            ['question' => 'Can I use TracePharma if my wholesaler mentions Pulse?', 'answer' => 'Yes. TracePharma ingests wholesaler EPCIS via SFTP, AS2, and HTTPS presets while Pulse directory integration is on our roadmap. Many customers run TracePharma for L4 operations today.'],
            ['question' => 'When will TracePharma join the Pulse partner program?', 'answer' => 'Pulse API certification is planned; we do not publish a go-live date yet. Subscribe to demo updates or contact sales for roadmap briefings.'],
        ];
    @endphp

    <x-marketing.faq :items="$pulseFaqs" title="NABP Pulse FAQ" />

    <x-marketing.cta-banner
        title="Evaluating Pulse and TracePharma together?"
        description="We'll explain EPCIS-layer cutover today and what Pulse certification will add when it ships."
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
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $pulseFaqs),
        ];
    @endphp
    <x-marketing.json-ld :data="$faqSchema" />
@endpush
