@extends('marketing.layout')

@section('title', 'Resources — TracePharma')
@section('meta_description', 'DSCSA guides, blog articles, and illustrative deployment patterns for manufacturers, wholesalers, and pharmacies evaluating L4 traceability.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Resources"
        title="Guides, articles, and deployment patterns for DSCSA teams"
        description="Practical content for receiving supervisors, compliance leads, and IT engineers—linked to TracePharma features, glossary terms, and honest provider comparisons."
    >
        <x-slot:breadcrumb>
            Resources
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.guides.epcis-vs-asn') }}">EPCIS vs ASN guide →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <h2 class="text-lg font-semibold text-tp-ink">Blog</h2>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-tp-muted">Operational articles for US L4 traceability—receiving, returns, provider evaluation, and exception investigation.</p>
        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            @foreach (\App\Support\Marketing\MarketingResources::allBlogPosts() as $post)
                <a href="{{ route('marketing.blog.show', $post['slug']) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-muted">{{ $post['published_at'] }}</p>
                    <h3 class="mt-2 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $post['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $post['summary'] }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read article →</span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-14 sm:px-6">
        <h2 class="text-lg font-semibold text-tp-ink">Customer patterns</h2>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-tp-muted">Anonymized illustrative deployment patterns—not named customer endorsements. Map them to your operating profile before a demo.</p>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (\App\Support\Marketing\MarketingResources::allCaseStudies() as $study)
                <a href="{{ route('marketing.customers.show', $study['slug']) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">{{ $study['organization_type'] }}</p>
                    <h3 class="mt-2 text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $study['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $study['summary'] }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View pattern →</span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-14 sm:px-6">
        <h2 class="text-lg font-semibold text-tp-ink">Guides &amp; glossary</h2>
        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <a href="{{ route('marketing.guides.epcis-vs-asn') }}" class="tp-card-accent border-tp-accent-500/30 group p-6 transition hover:border-tp-accent-500/50">
                <h3 class="text-lg font-semibold text-tp-ink group-hover:text-tp-link">EPCIS vs ASN</h3>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">What trading partners actually send—and how TracePharma uses each document type at receiving.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read guide →</span>
            </a>
            <a href="{{ route('marketing.glossary') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <h3 class="text-lg font-semibold text-tp-ink group-hover:text-tp-link">DSCSA glossary</h3>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">EPCIS, VRS, 3T, GTIN, GLN, saleable returns, and more—linked to product features.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Browse terms →</span>
            </a>
            <a href="{{ route('marketing.compare.checklist') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <h3 class="text-lg font-semibold text-tp-ink group-hover:text-tp-link">DSCSA provider checklist</h3>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Questions to ask every L4 vendor before you sign—downloadable PDF included.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View checklist →</span>
            </a>
            <a href="{{ route('marketing.compare.index') }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                <h3 class="text-lg font-semibold text-tp-ink group-hover:text-tp-link">Compare providers</h3>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Honest fit assessments vs LSPedia, InfiniTrak, TraceLink, and other DSCSA platforms.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Compare hub →</span>
            </a>
        </div>
    </section>

    <x-marketing.buyer-voice />

    <x-marketing.cta-banner
        title="Map resources to your workflow"
        description="Request a demo—we'll connect these patterns to your receiving, verification, and compliance screens."
    />
@endsection
