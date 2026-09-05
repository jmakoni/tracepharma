@extends('marketing.layout')

@section('title', 'TracePharma — L4 DSCSA traceability for the US supply chain')
@section('meta_description', 'L4 DSCSA traceability for manufacturers, wholesalers, 3PLs, and dispensers—EPCIS receive and ship, exceptions, and audit-ready compliance in one workspace.')

@section('content')
    <section class="relative overflow-hidden border-b border-tp-border">
        <div class="absolute inset-0 tp-hero-glow" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20">
            <x-marketing.custody-trace />

            <div class="mt-10 grid gap-10 lg:grid-cols-2 lg:items-start">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-wide text-tp-teal-400">L4 DSCSA traceability for the US supply chain</p>
                    <h1 class="tp-display mt-4 text-3xl font-medium tracking-tight text-tp-ink sm:text-4xl lg:text-5xl">
                        Your corporate traceability hub—from plant floor to trading partner dock.
                    </h1>
                    <p class="mt-5 text-lg leading-relaxed text-tp-muted">
                        TracePharma is your L4 corporate EPCIS hub—receiving, outbound ship, exceptions, and compliance in one profile-tuned workspace. Manufacturers, wholesalers, 3PLs, and dispensers get the workflows they run daily, not a one-size enterprise suite.
                    </p>
                    <p class="mt-3 text-sm text-tp-muted">Request a demo → 30–45 minute walkthrough → scoped proposal. No public tier table.</p>
                    <div class="mt-8 flex flex-wrap gap-4">
                        <a href="{{ route('marketing.demo') }}" class="tp-btn-primary">Request a demo</a>
                        <a href="{{ route('marketing.features') }}" class="tp-btn-ghost">Explore features</a>
                    </div>
                </div>
                <x-marketing.l4-stack class="lg:mt-2" />
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink sm:text-3xl">What you get on day one</h2>
            <p class="mt-4 text-tp-muted">
                Shipped L4 capabilities your operators use on shift—receiving, ship, verify, and investigate—not roadmap slides.
            </p>
        </div>

        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-marketing.feature-card title="EPCIS receiving &amp; shipping" description="Ingest inbound files via upload, SFTP, AS2, or webhooks—so dock staff match scans to partner events instead of reconciling spreadsheets." />
            <x-marketing.feature-card title="L3 ↔ L4 serialization" description="Configure an L3 forward URL and POST authored commissioning EPCIS to your plant endpoint—so you ship DSCSA outbound without replacing line software." />
            <x-marketing.feature-card title="Exception workflows" description="Structured reason codes and supplier correction loops—so compliance leads close tickets with evidence, not email threads." />
            <x-marketing.feature-card title="Partner connectivity" description="Direct AS2, SFTP, and HTTPS to your known partners—so IT provisions presets without a mandatory exchange middleman." />
            <x-marketing.feature-card title="VRS verification" description="Workstation and API verification with full audit log—so dispensers block unverified fills and keep inspection-ready history." />
            <x-marketing.feature-card title="Compliance exports" description="FDA Form 3911 prefill, verification summaries, and activity log—so auditors get packages in minutes, not days of manual assembly." />
            <x-marketing.feature-card title="Operations Hub" description="Scan a barcode and route to receive, verify, ship, or trace—so floor staff start from one entry point instead of hunting menus." />
            <x-marketing.feature-card title="Returns &amp; recall" description="Saleable return outbound, reverse logistics scorecard, and recall investigation—so manufacturer and wholesaler profiles close the loop on returns (profile-gated)." />
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-tp-ink sm:text-3xl">
                        “Free” DSCSA tools often shift cost to your operators
                    </h2>
                    <p class="mt-4 leading-relaxed text-tp-muted">
                        Portals that only store PDFs leave warehouse staff, plant QA, and dispensers manually reconciling serials, chasing corrected EPCIS, and scrambling when a shipment or verification fails mid-shift.
                    </p>
                    <p class="mt-4 leading-relaxed text-tp-muted">
                        TracePharma is built for operators who need closed-loop L4 traceability—license checks on partners, ACK health on outbound, exception investigation, and onboarding checklists that keep go-live on track.
                    </p>
                    <div class="mt-6 flex flex-wrap gap-4">
                        <a href="{{ route('marketing.compare.free-dscsa') }}" class="inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                            Read: why free isn't free →
                        </a>
                        <a href="{{ route('marketing.compare.lspedia') }}" class="inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                            Compare vs LSPedia →
                        </a>
                    </div>
                </div>
                <div class="tp-card p-8">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-tp-muted">Questions to ask any provider</h3>
                    <ul class="mt-5 space-y-4 text-sm text-tp-muted">
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> Can I receive and ship EPCIS from the same L4 workspace?</li>
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> Do you support L3 commissioning forward without replacing my line software?</li>
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> Can I resolve exceptions and document supplier follow-up?</li>
                        <li class="flex gap-3"><span class="font-mono text-tp-teal-400">→</span> Is outbound ACK health visible per customer or principal?</li>
                    </ul>
                    <a href="{{ route('marketing.compare.checklist') }}" class="mt-6 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                        Full provider checklist →
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="max-w-2xl">
                <p class="text-sm font-semibold uppercase tracking-wide text-tp-teal-400">For drug manufacturers</p>
                <h2 class="mt-3 text-2xl font-semibold tracking-tight text-tp-ink sm:text-3xl">L4 hub from packaging line to wholesaler dock</h2>
                <p class="mt-4 leading-relaxed text-tp-muted">
                    Forward authored commissioning EPCIS to your plant-floor L3 endpoint, ship DSCSA outbound EPCIS, and monitor wholesaler ACK health—without TraceLink-scale network fees or replacing the line software you already run.
                </p>
                <div class="mt-6 flex flex-wrap gap-4">
                    <a href="{{ route('marketing.solutions.manufacturers') }}" class="tp-btn-ghost">Manufacturer solution</a>
                    <a href="{{ route('marketing.features.show', 'serialization') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">L3 ↔ L4 serialization →</a>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div class="max-w-2xl mx-auto text-center">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink sm:text-3xl">Solutions by industry</h2>
            <p class="mt-3 text-tp-muted">One L4 platform—profile-tuned workflows for manufacturers, wholesalers, 3PLs, dispensers, and specialty roles.</p>
        </div>
        <x-marketing.industry-picker
            class="mt-10"
            :industries="[
                ['label' => 'Manufacturer', 'title' => 'Drug manufacturers', 'description' => 'L3 commissioning forward, outbound EPCIS, and customer ACK health.', 'href' => route('marketing.solutions.manufacturers'), 'highlight' => true],
                ['label' => 'Distributor', 'title' => 'Drug wholesalers', 'description' => 'Receive-to-ship L4 with ACK monitoring and exceptions.', 'href' => route('marketing.solutions.wholesalers'), 'highlight' => true],
                ['label' => '3PL', 'title' => 'Logistics & 3PL', 'description' => 'Principal-scoped receiving, cross-dock, and lot-level ship.', 'href' => route('marketing.solutions.3pl'), 'highlight' => true],
                ['label' => 'Dispenser', 'title' => 'Pharmacies', 'description' => 'Receive, verify, dispense, and file FDA 3911.', 'href' => route('marketing.solutions.pharmacies')],
                ['label' => 'Repack', 'title' => 'Prepackagers', 'description' => 'Bulk receive, repack lineage, and outbound EPCIS with new serials.', 'href' => route('marketing.solutions.prepackagers')],
                ['label' => 'Network', 'title' => 'Buying groups', 'description' => 'Member health, exception trends, and partner authorization.', 'href' => route('marketing.solutions.buying-groups')],
                ['label' => 'Supply', 'title' => 'Dental & medical', 'description' => 'Mixed Rx/non-Rx catalog with practice ship-to GLNs.', 'href' => route('marketing.solutions.dental-medical')],
            ]"
        />
    </section>

    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 class="text-center text-2xl font-semibold tracking-tight text-tp-ink sm:text-3xl">How teams use TracePharma</h2>
        <p class="mx-auto mt-3 max-w-2xl text-center text-tp-muted">Same L4 platform—different entry points depending on your operating profile.</p>
        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Manufacturer</p>
                <p class="mt-3 font-semibold text-tp-ink">Ship with attached 3T</p>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Forward commissioning to L3, generate outbound EPCIS, and monitor customer ACK health.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Wholesaler</p>
                <p class="mt-3 font-semibold text-tp-ink">Receive to ship</p>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Match inbound EPCIS, resolve exceptions before inventory, and ship downstream with SSCC labels and partner routing.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">3PL</p>
                <p class="mt-3 font-semibold text-tp-ink">3PL floor + soft principal tags</p>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Same receive → ship spine as wholesalers, with optional principal labels on sites and ship orders for filtering — not custody-isolated inventory partitions.</p>
            </div>
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Dispenser</p>
                <p class="mt-3 font-semibold text-tp-ink">Verify before dispense</p>
                <p class="mt-2 text-sm leading-relaxed text-tp-muted">Receive from wholesalers, run VRS checks at the workstation or via API, and file FDA 3911 from failed verifications.</p>
            </div>
        </div>
    </section>

    @php
        $homeFaqs = [
            ['question' => 'What is L4 DSCSA traceability?', 'answer' => 'Level 4 is your corporate EPCIS hub—the system that receives shipment events from partners, matches transaction information (TI), history (TH), and statement (TS), ships outbound EPCIS, and maintains audit-ready records for FDA DSCSA. TracePharma operates at L4 for US trading partners.'],
            ['question' => 'Who is TracePharma built for?', 'answer' => 'Manufacturers, wholesalers, 3PLs, prepackagers, buying groups, and dispensers who need EPCIS-native receiving and shipping—not a global L5 hub or EU FMD country gateway. Each tenant profile tunes navigation and workflows to your operating model.'],
            ['question' => 'How do I get pricing?', 'answer' => 'TracePharma quotes after a demo and scoping call. Packaging depends on operating profile, site count, partner connections, and optional modules. Visit the pricing page or request a demo with your organization type selected.'],
            ['question' => 'Does TracePharma replace my wholesaler portal?', 'answer' => 'TracePharma complements or replaces document-only portals by ingesting EPCIS, matching 3T data, running VRS verification where your profile requires it, and structuring exceptions with supplier accountability—not just archiving PDFs.'],
        ];
    @endphp

    <x-marketing.faq :items="$homeFaqs" />

    <x-marketing.cta-banner
        title="See TracePharma with your workflows"
        description="Walk through the flows that match your operating profile—manufacturer outbound, wholesaler receive-to-ship, 3PL principals, or dispenser verification. Pick your profile when you request a demo."
    />
@endsection
