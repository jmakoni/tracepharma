@extends('marketing.layout')

@section('title', 'Integrations — TracePharma')
@section('meta_description', 'TracePharma interoperates with TraceLink, LSPedia, InfiniTrak, Advasur, Gateway Checker, UniTrace, and Axway via AS2, SFTP, and HTTPS EPCIS presets.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Integrations"
        title="Interoperate with the platforms your partners already use"
        description="TracePharma connects to serialization vendors, pharmacy platforms, and middleware listed in the NABP Pulse ecosystem. Use tenant-scoped AS2, SFTP, and HTTPS presets. Run L4 receiving, outbound ship, exceptions, and compliance in one workspace."
    >
        <x-slot:actions>
            <a href="{{ route('marketing.features.show', 'integrations') }}">Integration features →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="tp-card border-amber-500/30 bg-amber-500/5 p-6 text-sm leading-relaxed text-tp-muted">
            <p class="font-semibold text-tp-ink">NABP Pulse interoperability</p>
            <p class="mt-2">
                Many vendors below participate in the
                <a href="https://pulse.pharmacy/solutions/pulse-interoperable-partners/" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200" rel="noopener noreferrer">Pulse Interoperable Partner program</a>.
                TracePharma interoperates at the <strong class="text-tp-ink">EPCIS transport layer</strong> with these platforms. Receive and send standards-based shipment data to trading partners on TraceLink, LSPedia, InfiniTrak, and others.
            </p>
            <p class="mt-3">
                TracePharma is <strong class="text-tp-ink">not yet listed</strong> as a Pulse-certified solution provider.
                <a href="{{ route('marketing.integrations.nabp-pulse') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Read our Pulse interoperability status →</a>
            </p>
        </div>

        <h2 class="mt-14 text-2xl font-semibold tracking-tight text-tp-ink">WMS, PMS, wholesalers &amp; EDI</h2>
        <p class="mt-3 max-w-3xl text-sm leading-relaxed text-tp-muted">
            Connect warehouse, pharmacy, wholesaler, and middleware systems—not just DSCSA serialization vendors.
        </p>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('marketing.integrations.pms.index') }}" class="tp-card-accent group border-tp-accent-500/30 p-6 transition hover:border-tp-accent-500/50">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pharmacy PMS</p>
                <h3 class="mt-3 font-semibold text-tp-ink group-hover:text-tp-link">7 dispense-check APIs</h3>
                <p class="mt-2 text-sm text-tp-muted">PioneerRx, BestRx, PrimeRx, and more</p>
            </a>
            <a href="{{ route('marketing.integrations.wms.index') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">WMS</p>
                <h3 class="mt-3 font-semibold text-tp-ink group-hover:text-tp-link">Ship-confirm bridge</h3>
                <p class="mt-2 text-sm text-tp-muted">Manhattan &amp; Körber / HighJump</p>
            </a>
            <a href="{{ route('marketing.integrations.wholesale.index') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Wholesalers</p>
                <h3 class="mt-3 font-semibold text-tp-ink group-hover:text-tp-link">Inbound EPCIS presets</h3>
                <p class="mt-2 text-sm text-tp-muted">Cardinal, McKesson, Cencora</p>
            </a>
            <a href="{{ route('marketing.integrations.edi-as2') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">AS2 &amp; EDI</p>
                <h3 class="mt-3 font-semibold text-tp-ink group-hover:text-tp-link">Middleware &amp; transport</h3>
                <p class="mt-2 text-sm text-tp-muted">AS2, SFTP, Axway patterns</p>
            </a>
            <a href="{{ route('marketing.integrations.erp.index') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">ERP</p>
                <h3 class="mt-3 font-semibold text-tp-ink group-hover:text-tp-link">SAP &amp; ERP adjacency</h3>
                <p class="mt-2 text-sm text-tp-muted">ATTP + REST/webhook patterns</p>
            </a>
            <a href="{{ route('marketing.integrations.nabp-pulse') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">NABP Pulse</p>
                <h3 class="mt-3 font-semibold text-tp-ink group-hover:text-tp-link">Interoperability status</h3>
                <p class="mt-2 text-sm text-tp-muted">Pulse ecosystem &amp; roadmap</p>
            </a>
        </div>

        <h2 class="mt-14 text-2xl font-semibold tracking-tight text-tp-ink">Serialization &amp; L4 platforms</h2>
        <div class="mt-8 grid gap-4 md:grid-cols-2">
            @foreach (['tracelink', 'lspedia', 'unitrace', 'gateway-checker'] as $slug)
                @php $page = \App\Support\Marketing\MarketingIntegrationPages::get($slug); @endphp
                <a href="{{ route('marketing.integrations.show', $slug) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">{{ $page['category'] }}</p>
                        @if ($page['pulse_listed'])
                            <span class="rounded-full border border-tp-border px-2 py-0.5 text-xs text-tp-muted">Pulse ecosystem</span>
                        @endif
                    </div>
                    <h3 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $page['name'] }}</h3>
                    <p class="mt-2 text-sm text-tp-muted">{{ implode(' · ', $page['transports']) }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">{{ Str::limit($page['hero_description'], 140) }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View interoperability →</span>
                </a>
            @endforeach
        </div>

        <h2 class="mt-14 text-2xl font-semibold tracking-tight text-tp-ink">Pharmacy platforms</h2>
        <div class="mt-8 grid gap-4 md:grid-cols-2">
            @foreach (['infinitrak', 'advasur'] as $slug)
                @php $page = \App\Support\Marketing\MarketingIntegrationPages::get($slug); @endphp
                <a href="{{ route('marketing.integrations.show', $slug) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">{{ $page['category'] }}</p>
                        @if ($page['pulse_listed'])
                            <span class="rounded-full border border-tp-border px-2 py-0.5 text-xs text-tp-muted">Pulse ecosystem</span>
                        @endif
                    </div>
                    <h3 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $page['name'] }}</h3>
                    <p class="mt-2 text-sm text-tp-muted">{{ implode(' · ', $page['transports']) }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">{{ Str::limit($page['hero_description'], 140) }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View interoperability →</span>
                </a>
            @endforeach
        </div>

        <h2 class="mt-14 text-2xl font-semibold tracking-tight text-tp-ink">Middleware</h2>
        <div class="mt-8 grid gap-4 md:grid-cols-2">
            @php $page = \App\Support\Marketing\MarketingIntegrationPages::get('axway'); @endphp
            <a href="{{ route('marketing.integrations.show', 'axway') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">{{ $page['category'] }}</p>
                    <span class="rounded-full border border-tp-border px-2 py-0.5 text-xs text-tp-muted">Pulse ecosystem</span>
                </div>
                <h3 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $page['name'] }}</h3>
                <p class="mt-2 text-sm text-tp-muted">{{ implode(' · ', $page['transports']) }}</p>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">{{ Str::limit($page['hero_description'], 140) }}</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View interoperability →</span>
            </a>
        </div>

        <h2 class="mt-14 text-2xl font-semibold tracking-tight text-tp-ink">ERP &amp; additional Pulse ecosystem vendors</h2>
        <div class="mt-8 grid gap-4 md:grid-cols-2">
            @foreach (['sap', 'rfxcel', 'tracktracerx', 'optel'] as $slug)
                @php $page = \App\Support\Marketing\MarketingIntegrationPages::get($slug); @endphp
                <a href="{{ route('marketing.integrations.show', $slug) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">{{ $page['category'] }}</p>
                        @if ($page['pulse_listed'])
                            <span class="rounded-full border border-tp-border px-2 py-0.5 text-xs text-tp-muted">Pulse ecosystem</span>
                        @endif
                    </div>
                    <h3 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $page['name'] }}</h3>
                    <p class="mt-2 text-sm text-tp-muted">{{ implode(' · ', $page['transports']) }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">{{ Str::limit($page['hero_description'], 140) }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View interoperability →</span>
                </a>
            @endforeach
        </div>

        <h2 class="mt-14 text-2xl font-semibold tracking-tight text-tp-ink">Also supported in tenant onboarding</h2>
        <p class="mt-3 max-w-3xl text-sm leading-relaxed text-tp-muted">
            Wholesaler presets, PMS dispense-check, and WMS ship-confirm are configured in your tenant onboarding wizard. See
            <a href="{{ route('marketing.integrations.pms.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">PMS integrations</a>,
            <a href="{{ route('marketing.integrations.wms.index') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">WMS integrations</a>, and
            <a href="{{ route('marketing.features.show', 'integrations') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">technical features</a>.
        </p>
    </section>

    <x-marketing.cta-banner
        title="Mapping a cutover from your current vendor?"
        description="Tell us which platform your partners use—we'll show the preset, test receiving flow, and honest migration steps in a demo."
    />
@endsection
