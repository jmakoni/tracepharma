@extends('marketing.layout')

@section('title', 'DSCSA provider checklist — TracePharma')
@section('meta_description', 'Questions to ask any DSCSA traceability provider before you sign: VRS, EPCIS, exceptions, 3911, integrations, and data ownership.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Evaluate providers"
        title="DSCSA provider checklist"
        description="Use these questions in RFPs, wholesaler meetings, or internal steering committees. If the answer is “not yet” or “extra fee,” factor that into your true cost."
    >
        <x-slot:actions>
            <a href="{{ route('marketing.compare.checklist.pdf') }}" class="tp-btn-ghost">
                Download PDF checklist
            </a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="space-y-10">
            @foreach ($sections as $heading => $questions)
                <div class="tp-card p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-tp-ink">{{ $heading }}</h2>
                    <ul class="mt-5 space-y-3">
                        @foreach ($questions as $question)
                            <li class="flex gap-3 text-sm leading-relaxed text-tp-muted">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-tp-accent-500/30 bg-tp-accent-500/10 text-xs font-semibold text-tp-teal-400">✓</span>
                                <span>{{ $question }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    <section class="border-t border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-xl font-semibold text-tp-ink">How TracePharma answers today</h2>
            <p class="mt-4 max-w-3xl leading-relaxed text-tp-muted">
                Every section above maps to shipped product capability—not a roadmap slide. Request a demo to walk through your wholesaler file formats, VRS endpoints, and exception process with our team.
            </p>
            <div class="mt-6 flex flex-wrap gap-4">
                <a href="{{ route('marketing.demo') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Request a demo →</a>
                <a href="{{ route('marketing.features') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">View feature list →</a>
                <a href="{{ route('marketing.compare.free-dscsa') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Why free isn't free →</a>
                <a href="{{ route('marketing.guides.epcis-vs-asn') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">EPCIS vs ASN guide →</a>
            </div>
        </div>
    </section>

    <x-marketing.cta-banner
        title="Run this checklist with us"
        description="Request a demo—we'll go question by question and show where TracePharma fits your operation, using your file formats and partner list."
    />

    <x-marketing.checklist-sticky-bar />
@endsection
