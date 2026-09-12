@extends('marketing.layout')

@section('title', 'Pharmacy PMS integrations — TracePharma')
@section('meta_description', 'TracePharma POST /api/v1/dispense-check for pharmacy PMS middleware—block fills until DSCSA verification passes. Named per-vendor adapters are not GA.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Integrations · Pharmacy PMS"
        title="One dispense-check API for pharmacy systems"
        description="Keep your PMS as the system of record. TracePharma gates dispense via POST /api/v1/dispense-check, logs blocked reasons, and feeds your dispenser compliance scorecard. Named per-vendor adapter routes are not GA—common PMS vendors are listed as integration targets for the same Sanctum endpoint."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.integrations.index') }}">Integrations</a> / Pharmacy PMS
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.solutions.pharmacies') }}">Pharmacy solution →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach (\App\Support\Marketing\MarketingPlatformIntegrations::pmsDefinitions() as $page)
                <a href="{{ route('marketing.integrations.pms.show', $page['slug']) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pharmacy PMS</p>
                    <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $page['name'] }}</h2>
                    <p class="mt-2 text-sm text-tp-muted">POST /api/v1/dispense-check</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">{{ Str::limit($page['hero_description'], 120) }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View integration →</span>
                </a>
            @endforeach
        </div>
    </section>

    <x-marketing.cta-banner
        title="Need a PMS cutover plan?"
        description="Tell us your pharmacy system—we'll show POST /api/v1/dispense-check setup, scorecard reporting, and wholesaler receiving on the same tenant."
    />
@endsection
