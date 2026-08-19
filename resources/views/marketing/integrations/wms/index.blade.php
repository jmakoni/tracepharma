@extends('marketing.layout')

@section('title', 'WMS integrations — TracePharma')
@section('meta_description', 'TracePharma WMS ship-confirm bridge for Manhattan Active WM and Körber HighJump—outbound EPCIS from warehouse ship events.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Integrations · WMS"
        title="Ship-confirm bridges for warehouse systems"
        description="Your WMS runs pick, pack, and ship. TracePharma turns ship-confirm callbacks into outbound EPCIS—with blocked-reason audit on the operations scorecard."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.integrations.index') }}">Integrations</a> / WMS
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.solutions.wholesalers') }}">Wholesaler solution →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (\App\Support\Marketing\MarketingPlatformIntegrations::wmsDefinitions() as $page)
                <a href="{{ route('marketing.integrations.wms.show', $page['slug']) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Warehouse management</p>
                    <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $page['name'] }}</h2>
                    <p class="mt-2 text-sm text-tp-muted">HTTPS ship-confirm webhook</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">{{ Str::limit($page['hero_description'], 140) }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View integration →</span>
                </a>
            @endforeach
        </div>
    </section>

    <x-marketing.cta-banner
        title="Running a different WMS?"
        description="REST API and outbound webhooks support custom middleware—we'll review your ship-confirm JSON shape in a demo."
    />
@endsection
