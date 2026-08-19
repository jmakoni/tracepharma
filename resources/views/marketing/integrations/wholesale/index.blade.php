@extends('marketing.layout')

@section('title', 'Wholesaler EPCIS integrations — TracePharma')
@section('meta_description', 'TracePharma inbound presets for Cardinal, McKesson, Cencora, and Morris & Dickson—SFTP, AS2, and HTTPS EPCIS receiving for DSCSA.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Integrations · Wholesalers"
        title="Inbound EPCIS from major US wholesalers"
        description="Onboarding wizard presets for Cardinal SFTP, McKesson AS2/HTTPS, Cencora SFTP, and Morris & Dickson HTTPS capture—so receiving teams start with the right transport and partner GLN."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.integrations.index') }}">Integrations</a> / Wholesalers
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.solutions.pharmacies') }}">Pharmacy solution →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (\App\Support\Marketing\MarketingPlatformIntegrations::wholesaleDefinitions() as $page)
                <a href="{{ route('marketing.integrations.wholesale.show', $page['slug']) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Drug wholesaler</p>
                    <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $page['name'] }}</h2>
                    <p class="mt-2 text-sm text-tp-muted">{{ implode(' · ', $page['transports']) }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">{{ Str::limit($page['hero_description'], 140) }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View preset →</span>
                </a>
            @endforeach
        </div>
    </section>

    <x-marketing.cta-banner
        title="Receiving from a regional wholesaler?"
        description="Custom AS2, SFTP, and HTTPS inbound connections cover partners beyond the Big Three—we'll map your file samples in a demo."
    />
@endsection
