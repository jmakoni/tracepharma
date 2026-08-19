<footer class="border-t border-tp-border bg-tp-surface">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
        <div class="grid gap-10 md:grid-cols-3">
            <div>
                <img src="/images/brand/logo.svg" alt="TracePharma" class="h-8 w-auto">
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-tp-muted">
                    L4 DSCSA traceability for manufacturers, wholesalers, 3PLs, and dispensers. EPCIS receiving and shipping, exceptions, and audit-ready compliance in one tenant workspace.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-tp-ink">Product</h2>
                <ul class="mt-4 space-y-2 text-sm text-tp-muted">
                    <li><a href="{{ route('marketing.features') }}" class="hover:text-tp-link">Features</a></li>
                    <li><a href="{{ route('marketing.integrations.index') }}" class="hover:text-tp-link">Integrations</a></li>
                    <li><a href="{{ route('marketing.pricing') }}" class="hover:text-tp-link">Pricing</a></li>
                    <li><a href="{{ route('marketing.features.show', 'serialization') }}" class="hover:text-tp-link">Serialization &amp; L3 provisioning</a></li>
                    <li><a href="{{ route('marketing.solutions.manufacturers') }}" class="hover:text-tp-link">Drug manufacturers</a></li>
                    <li><a href="{{ route('marketing.solutions.wholesalers') }}" class="hover:text-tp-link">Drug wholesalers</a></li>
                    <li><a href="{{ route('marketing.solutions.3pl') }}" class="hover:text-tp-link">3PL &amp; logistics</a></li>
                    <li><a href="{{ route('marketing.solutions.pharmacies') }}" class="hover:text-tp-link">Pharmacies</a></li>
                    <li><a href="{{ route('marketing.solutions.buying-groups') }}" class="hover:text-tp-link">Buying groups</a></li>
                    <li><a href="{{ route('marketing.solutions.dental-medical') }}" class="hover:text-tp-link">Dental &amp; medical supply</a></li>
                    <li><a href="{{ route('marketing.solutions.prepackagers') }}" class="hover:text-tp-link">Prepackagers</a></li>
                    <li><a href="{{ route('marketing.resources.index') }}" class="hover:text-tp-link">Resources</a></li>
                    <li><a href="{{ route('marketing.guides.epcis-vs-asn') }}" class="hover:text-tp-link">EPCIS vs ASN guide</a></li>
                    <li><a href="{{ route('marketing.glossary') }}" class="hover:text-tp-link">DSCSA glossary</a></li>
                    <li><a href="{{ route('marketing.glossary.show', 'epcis') }}" class="hover:text-tp-link">What is EPCIS?</a></li>
                    <li><a href="{{ route('marketing.compare.index') }}" class="hover:text-tp-link">Compare providers</a></li>
                    <li><a href="{{ route('marketing.compare.lspedia') }}" class="hover:text-tp-link">LSPedia alternative</a></li>
                    <li><a href="{{ route('marketing.compare.tracelink') }}" class="hover:text-tp-link">TraceLink alternative</a></li>
                    <li><a href="{{ route('marketing.compare.infinitrak') }}" class="hover:text-tp-link">InfiniTrak alternative</a></li>
                    <li><a href="{{ route('marketing.compare.advasur') }}" class="hover:text-tp-link">Advasur alternative</a></li>
                    <li><a href="{{ route('marketing.compare.gateway-checker') }}" class="hover:text-tp-link">Gateway Checker alternative</a></li>
                    <li><a href="{{ route('marketing.compare.tracktracerx') }}" class="hover:text-tp-link">TrackTraceRx alternative</a></li>
                    <li><a href="{{ route('marketing.compare.optel') }}" class="hover:text-tp-link">OPTEL alternative</a></li>
                    <li><a href="{{ route('marketing.integrations.nabp-pulse') }}" class="hover:text-tp-link">NABP Pulse status</a></li>
                    <li><a href="{{ route('marketing.integrations.erp.index') }}" class="hover:text-tp-link">ERP integrations</a></li>
                    <li><a href="{{ route('marketing.compare.free-dscsa') }}" class="hover:text-tp-link">Why free DSCSA isn't free</a></li>
                    <li><a href="{{ route('marketing.compare.checklist') }}" class="hover:text-tp-link">Provider checklist</a></li>
                    <li><a href="{{ route('marketing.compare.checklist.pdf') }}" class="hover:text-tp-link">Download checklist PDF</a></li>
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-tp-ink">Get started</h2>
                <ul class="mt-4 space-y-2 text-sm text-tp-muted">
                    <li><a href="{{ route('marketing.demo') }}" class="hover:text-tp-link">Request a demo</a></li>
                    <li><a href="{{ route('marketing.get-started') }}" class="hover:text-tp-link">Get started</a></li>
                    <li><a href="{{ route('marketing.about') }}" class="hover:text-tp-link">About TracePharma</a></li>
                    <li><a href="{{ route('marketing.contact') }}" class="hover:text-tp-link">Contact</a></li>
                </ul>
                <p class="mt-4 text-sm leading-relaxed text-tp-muted">
                    See receiving, outbound ship, exceptions, and compliance reporting tuned to your operating profile—manufacturer, distributor, 3PL, or dispenser.
                </p>
                <a href="{{ route('marketing.demo') }}" class="tp-btn-primary mt-4 inline-flex text-sm">
                    Request a demo
                </a>
            </div>
        </div>

        <p class="mt-10 border-t border-tp-border pt-6 text-xs text-tp-muted">
            © {{ date('Y') }} {{ \App\Support\Marketing\TermsOfService::productName() }}.
            {{ \App\Support\Marketing\TermsOfService::productAttribution() }}
            L4 DSCSA traceability for US supply-chain trading partners.
            <span class="mx-2 text-white/25">·</span>
            <a href="{{ route('marketing.tos') }}" class="hover:text-tp-link">Terms of Service</a>
            <span class="mx-2 text-white/25">·</span>
            <a href="{{ route('marketing.privacy') }}" class="hover:text-tp-link">Privacy Policy</a>
        </p>
    </div>
</footer>
