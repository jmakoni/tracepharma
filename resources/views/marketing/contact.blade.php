@extends('marketing.layout')

@section('title', 'Contact TracePharma — demo & support')
@section('meta_description', 'Contact TracePharma for a DSCSA demo or integration scoping. Use the demo request form—we reply to the email you provide when no sales inbox is configured.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Contact"
        title="Talk to us about your DSCSA cutover"
        description="Start with a demo request and your operating profile—we route you to the right workflow walkthrough. For existing customers and integration partners, use the contact options below."
    >
        <x-slot:breadcrumb>
            Contact
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-2">
            <div class="tp-card-accent border-tp-accent-500/30 p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Request a demo (recommended)</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                    Tell us whether you are a manufacturer, wholesaler, 3PL, dispenser, prepackager, buying group, or dental/medical distributor. We schedule a profile-tuned walkthrough and follow up with scoping and packaging options.
                </p>
                <a href="{{ route('marketing.demo') }}" class="tp-btn-primary mt-6 inline-flex text-sm">
                    Open demo request form
                </a>
            </div>

            <div class="tp-card p-8">
                <h2 class="text-lg font-semibold text-tp-ink">Sales &amp; support</h2>
                @if ($supportEmail = config('tracepharma.platform_support_email') ?: config('tracepharma.marketing.demo_notify_email'))
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                        Email
                        <a href="mailto:{{ $supportEmail }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">{{ $supportEmail }}</a>
                        for demo follow-ups, integration scoping, or product questions. For first contact, the demo form is still the fastest path.
                    </p>
                @else
                    <p class="mt-3 text-sm leading-relaxed text-tp-muted">
                        No dedicated sales inbox is published in this environment. Submit the
                        <a href="{{ route('marketing.demo') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">demo request form</a>
                        with your work email—we reply to the address you provide.
                    </p>
                @endif
                <p class="mt-4 text-sm leading-relaxed text-tp-muted">
                    For regulated cutover planning, see
                    <a href="{{ route('marketing.compare.checklist') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">DSCSA provider checklist</a>
                    and
                    <a href="{{ route('marketing.pricing') }}" class="font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">how we quote</a>.
                </p>
            </div>
        </div>
    </section>

    <x-marketing.cta-banner
        title="Not sure where to start?"
        description="Browse solution pages by profile or read the EPCIS vs ASN guide before your demo."
    />
@endsection
