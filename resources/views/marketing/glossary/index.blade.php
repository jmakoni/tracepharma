@extends('marketing.layout')

@section('title', 'DSCSA & GS1 glossary — TracePharma')
@section('meta_description', 'Plain-language definitions of EPCIS, VRS, 3T, GTIN, ASN, GLN, and SSCC for US pharmaceutical traceability teams.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Glossary"
        title="DSCSA and GS1 terms, explained for operators"
        description="Short definitions for receiving desks, compliance leads, and IT engineers evaluating L4 traceability—linked to TracePharma features and guides."
    >
        <x-slot:breadcrumb>
            Glossary
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.guides.epcis-vs-asn') }}">EPCIS vs ASN guide →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (\App\Support\Marketing\GlossaryTerms::all() as $term)
                <a href="{{ route('marketing.glossary.show', $term['slug']) }}" class="tp-card group p-6 transition hover:border-tp-teal-500/40">
                    <h2 class="text-lg font-semibold text-tp-ink group-hover:text-tp-link">{{ $term['title'] }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $term['summary'] }}</p>
                    <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read definition →</span>
                </a>
            @endforeach
        </div>
    </section>

    <x-marketing.cta-banner
        title="Need help mapping terms to your workflow?"
        description="Request a demo—we'll walk through EPCIS receiving, VRS, and exceptions on a profile tuned to your organization."
    />
@endsection
