@extends('marketing.layout')

@section('title', 'Compare DSCSA providers — TracePharma')
@section('meta_description', 'Compare TracePharma to LSPedia, InfiniTrak, and free DSCSA portals. Honest fit assessments, provider checklist, and evaluation guides for US L4 traceability.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="Evaluate DSCSA providers with an honest fit lens"
        description="Different vendors optimize for different buyers—global enterprise networks, turnkey pharmacy onboarding, or focused L4 operator UX. Use these pages to narrow your shortlist. Then request a demo scoped to your operating profile."
    >
        <x-slot:actions>
            <a href="{{ route('marketing.compare.checklist') }}">Download provider checklist →</a>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-6 md:grid-cols-2">
            <a href="{{ route('marketing.compare.lspedia') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Full-suite competitor</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">LSPedia alternative</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">When global OneScan breadth fits—and when US L4 operator UX, direct partner connectivity, and lower TCO matter more to your DC team.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read comparison →</span>
            </a>
            <a href="{{ route('marketing.compare.tracelink') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Network incumbent</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">TraceLink alternative</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Opus network scale vs mid-market direct AS2/SFTP connectivity and scan-first operator UX.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read comparison →</span>
            </a>
            <a href="{{ route('marketing.compare.infinitrak') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pharmacy competitor</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">InfiniTrak alternative</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Turnkey dispenser onboarding vs wholesaler-grade EPCIS depth, event-store investigation (1.2 GA; 2.0 capture + query-as-2.0), and buying-group scale.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read comparison →</span>
            </a>
            <a href="{{ route('marketing.compare.advasur') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pharmacy platform</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">Advasur alternative</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Guided Advasur 360 onboarding vs full L4 EPCIS depth and multi-site pharmacy operations.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read comparison →</span>
            </a>
            <a href="{{ route('marketing.compare.gateway-checker') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Regional connectivity</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">Gateway Checker alternative</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">HTTPS connectivity hub vs full receive-to-ship L4 for regional wholesalers.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read comparison →</span>
            </a>
            <a href="{{ route('marketing.compare.tracktracerx') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Pharmacy network</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">TrackTraceRx alternative</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Network onboarding scale vs EPCIS 1.2 GA / 2.0 capture, query-as-2.0, and HTTPS subscriptions and buying-group infrastructure.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read comparison →</span>
            </a>
            <a href="{{ route('marketing.compare.optel') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Global serialization</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">OPTEL alternative</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Fixed-cost global MAH platform vs US-focused mid-market L4 SaaS.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read comparison →</span>
            </a>
            <a href="{{ route('marketing.compare.free-dscsa') }}" class="tp-card group p-8 transition hover:border-tp-teal-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Evaluation guide</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">Why free DSCSA isn't free</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Hidden costs of zero-license portals: manual work, missing VRS audit trails, and exception gaps.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">Read guide →</span>
            </a>
            <a href="{{ route('marketing.compare.checklist') }}" class="tp-card-accent group border-tp-accent-500/30 p-8 transition hover:border-tp-accent-500/50">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Download</p>
                <h2 class="mt-3 text-lg font-semibold text-tp-ink group-hover:text-tp-link">DSCSA provider checklist</h2>
                <p class="mt-3 text-sm leading-relaxed text-tp-muted">Questions to ask every vendor before you sign—EPCIS depth, VRS, exceptions, partner connectivity, and implementation.</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-tp-link">View checklist →</span>
            </a>
        </div>
    </section>

    <x-marketing.buyer-voice />

    <x-marketing.cta-banner
        title="Still comparing?"
        description="Share your operating profile and current vendor. We'll map an honest fit assessment in a 30-minute demo—no obligation to switch."
    />
@endsection
