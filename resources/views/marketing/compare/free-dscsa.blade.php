@extends('marketing.layout')

@section('title', 'Why free DSCSA solutions are not truly free — TracePharma')
@section('meta_description', 'Hidden costs of free DSCSA portals: manual work, compliance gaps, missing VRS audit trails, and exception handling.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Compare"
        title="Is a “free” DSCSA solution truly free?"
        description="Zero license fees do not mean zero cost. When a portal only archives PDFs—or charges later for verification, exceptions, or integrations—you pay with operator time, audit risk, and delayed shipments across warehouse, plant, and dispenser roles."
    />

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-2">
            <div>
                <h2 class="text-xl font-semibold text-tp-ink">What “free” often includes</h2>
                <ul class="mt-5 space-y-4 text-sm leading-relaxed text-tp-muted">
                    <li class="tp-card p-4">Document storage for TI, TH, and TS files with limited search</li>
                    <li class="tp-card p-4">Basic user seats tied to a wholesaler or buying group contract</li>
                    <li class="tp-card p-4">Check-the-box reporting with no serial-level verification log</li>
                </ul>
            </div>
            <div>
                <h2 class="text-xl font-semibold text-tp-ink">What operators still pay for—one way or another</h2>
                <ul class="mt-5 space-y-4 text-sm leading-relaxed text-tp-muted">
                    <li class="tp-alert--warning p-4 text-tp-ink">Manual serial reconciliation when EPCIS and physical counts do not match</li>
                    <li class="tp-alert--warning p-4 text-tp-ink">Phone calls to trading partners when 3T or serial data is missing</li>
                    <li class="tp-alert--warning p-4 text-tp-ink">Investigation time when inbound EPCIS fails validation or outbound ACKs go stale</li>
                    <li class="tp-alert--warning p-4 text-tp-ink">Warehouse or dispenser time when VRS returns negative at verify or dispense</li>
                    <li class="tp-alert--warning p-4 text-tp-ink">Consultant or attorney hours preparing for DSCSA inspections</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Capability gaps that become compliance debt</h2>
            <div class="tp-marketing-table mt-8">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-tp-ink">Workflow</th>
                            <th class="px-4 py-3 text-left font-semibold text-tp-ink">Typical free portal</th>
                            <th class="px-4 py-3 text-left font-semibold text-tp-teal-400">TracePharma</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">VRS verification audit log</td>
                            <td class="px-4 py-3 text-tp-muted">Often absent or add-on</td>
                            <td class="px-4 py-3 text-tp-accent-300">Built-in workstation + API</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Exception → supplier correction</td>
                            <td class="px-4 py-3 text-tp-muted">Email outside the system</td>
                            <td class="px-4 py-3 text-tp-accent-300">Tracked correction requests</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">FDA Form 3911 from failure</td>
                            <td class="px-4 py-3 text-tp-muted">Manual re-entry</td>
                            <td class="px-4 py-3 text-tp-accent-300">Prefill from verification exception</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Verification period reports</td>
                            <td class="px-4 py-3 text-tp-muted">Export spreadsheets by hand</td>
                            <td class="px-4 py-3 text-tp-accent-300">Summary reports + compliance package</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Outbound EPCIS generation</td>
                            <td class="px-4 py-3 text-tp-muted">Separate module or not included</td>
                            <td class="px-4 py-3 text-tp-accent-300">Built-in ship workflows + SSCC labels</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">L3 serial handoff</td>
                            <td class="px-4 py-3 text-tp-muted">Enterprise add-on</td>
                            <td class="px-4 py-3 text-tp-accent-300">L3 forward URL + commissioning POST</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Integration webhooks</td>
                            <td class="px-4 py-3 text-tp-muted">Professional services quote</td>
                            <td class="px-4 py-3 text-tp-accent-300">Self-service webhook config</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-tp-ink">Partner license monitoring</td>
                            <td class="px-4 py-3 text-tp-muted">Not included</td>
                            <td class="px-4 py-3 text-tp-accent-300">Scheduled validation + risk flags</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">The right question is total cost of compliance</h2>
        <div class="mt-6 space-y-4 leading-relaxed text-tp-muted">
            <p>
                A DSCSA program needs more than document storage—whether you ship serialized product, receive-to-ship as a distributor, or verify at dispense. You need to prove custody transfers, that failures were investigated, and that trading partners were held accountable when data was wrong.
            </p>
            <p>
                TracePharma prices the full L4 workflow—because the alternative is paying with staff hours every time a shipment arrives without serials, an outbound ACK goes missing, or an inspector asks for evidence from last quarter.
            </p>
        </div>
        <a href="{{ route('marketing.compare.checklist') }}" class="mt-8 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
            Use our provider evaluation checklist →
        </a>
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <h2 class="text-xl font-semibold text-tp-ink">Solutions by trading partner role</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-tp-muted">
                Total cost of compliance depends on your DSCSA role — dispenser, distributor, manufacturer, or network operator. Compare role-specific workflows, not just license line items.
            </p>
            <ul class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
                <li><a href="{{ route('marketing.solutions.pharmacies') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Pharmacies →</a></li>
                <li><a href="{{ route('marketing.solutions.wholesalers') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Drug wholesalers →</a></li>
                <li><a href="{{ route('marketing.solutions.manufacturers') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Drug manufacturers →</a></li>
                <li><a href="{{ route('marketing.solutions.3pl') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">3PL &amp; logistics →</a></li>
                <li><a href="{{ route('marketing.solutions.buying-groups') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Buying groups →</a></li>
                <li><a href="{{ route('marketing.solutions.dental-medical') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Dental &amp; medical supply →</a></li>
                <li><a href="{{ route('marketing.solutions.prepackagers') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Prepackagers →</a></li>
            </ul>
        </div>
    </section>

    <x-marketing.cta-banner
        title="Compare TracePharma to your current setup"
        description="Bring your wholesaler file samples and exception stories. Request a demo—we'll show how closed-loop traceability changes the math for your operating profile."
    />

    <x-marketing.checklist-sticky-bar />
@endsection