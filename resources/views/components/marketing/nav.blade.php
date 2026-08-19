<header class="sticky top-0 z-50 border-b border-tp-border bg-tp-surface/90 backdrop-blur-md" x-data="{ open: false, industriesOpen: false, resourcesOpen: false }">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
        <a href="{{ route('marketing.home') }}" class="flex shrink-0 items-center gap-3">
            <img src="/images/brand/logo.svg" alt="TracePharma" class="h-8 w-auto">
        </a>

        <nav class="hidden min-w-0 items-center gap-5 text-sm font-medium text-tp-muted lg:flex" aria-label="Main">
            <a href="{{ route('marketing.features') }}" @class(['whitespace-nowrap text-tp-link' => request()->routeIs('marketing.features') || request()->routeIs('marketing.features.show')])>Features</a>
            <a href="{{ route('marketing.integrations.index') }}" @class(['whitespace-nowrap text-tp-link' => request()->routeIs('marketing.integrations.*')])>Integrations</a>
            <a href="{{ route('marketing.pricing') }}" @class(['whitespace-nowrap text-tp-link' => request()->routeIs('marketing.pricing')])>Pricing</a>

            <div class="relative" @mouseenter="industriesOpen = true" @mouseleave="industriesOpen = false">
                <button
                    type="button"
                    @class([
                        'inline-flex items-center gap-1 whitespace-nowrap transition hover:text-tp-ink',
                        'text-tp-link' => request()->routeIs('marketing.solutions.*'),
                    ])
                    @click="industriesOpen = !industriesOpen"
                    aria-expanded="false"
                    x-bind:aria-expanded="industriesOpen"
                >
                    Industries
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" d="M6 9l6 6 6-6" />
                    </svg>
                </button>
                <div
                    x-show="industriesOpen"
                    x-cloak
                    x-transition
                    class="absolute left-0 top-full z-50 mt-2 min-w-[14rem] rounded-lg border border-tp-border bg-tp-surface py-2 shadow-xl"
                >
                    <p class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-tp-muted">Supply chain</p>
                    <a href="{{ route('marketing.solutions.manufacturers') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Drug manufacturers</a>
                    <a href="{{ route('marketing.solutions.wholesalers') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Drug wholesalers</a>
                    <a href="{{ route('marketing.solutions.3pl') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">3PL &amp; logistics</a>
                    <a href="{{ route('marketing.solutions.prepackagers') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Prepackagers</a>
                    <p class="mt-2 border-t border-tp-border px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-tp-muted">Dispenser &amp; network</p>
                    <a href="{{ route('marketing.solutions.pharmacies') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Pharmacies</a>
                    <a href="{{ route('marketing.solutions.buying-groups') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Buying groups</a>
                    <a href="{{ route('marketing.solutions.dental-medical') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Dental &amp; medical supply</a>
                </div>
            </div>

            <div class="relative" @mouseenter="resourcesOpen = true" @mouseleave="resourcesOpen = false">
                <button
                    type="button"
                    @class([
                        'inline-flex items-center gap-1 whitespace-nowrap transition hover:text-tp-ink',
                        'text-tp-link' => request()->routeIs('marketing.resources.*') || request()->routeIs('marketing.blog.*') || request()->routeIs('marketing.customers.*') || request()->routeIs('marketing.glossary*') || request()->routeIs('marketing.compare.*') || request()->routeIs('marketing.guides.*'),
                    ])
                    @click="resourcesOpen = !resourcesOpen"
                    aria-expanded="false"
                    x-bind:aria-expanded="resourcesOpen"
                >
                    Resources
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" d="M6 9l6 6 6-6" />
                    </svg>
                </button>
                <div
                    x-show="resourcesOpen"
                    x-cloak
                    x-transition
                    class="absolute left-0 top-full z-50 mt-2 min-w-[14rem] rounded-lg border border-tp-border bg-tp-surface py-2 shadow-xl"
                >
                    <a href="{{ route('marketing.resources.index') }}" class="block px-4 py-2 text-sm font-semibold text-tp-ink hover:bg-tp-canvas hover:text-tp-link">Resources hub</a>
                    <p class="mt-1 border-t border-tp-border px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-tp-muted">Learn</p>
                    <a href="{{ route('marketing.guides.epcis-vs-asn') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">EPCIS vs ASN guide</a>
                    <a href="{{ route('marketing.glossary') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">DSCSA glossary</a>
                    <a href="{{ route('marketing.compare.checklist') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Provider checklist</a>
                    <p class="mt-2 border-t border-tp-border px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-tp-muted">Evaluate</p>
                    <a href="{{ route('marketing.compare.index') }}" class="block px-4 py-2 text-sm text-tp-muted hover:bg-tp-canvas hover:text-tp-link">Compare providers</a>
                </div>
            </div>

            <a href="{{ route('marketing.about') }}" @class(['whitespace-nowrap text-tp-link' => request()->routeIs('marketing.about')])>About</a>
            <a href="{{ route('marketing.contact') }}" @class(['whitespace-nowrap text-tp-link' => request()->routeIs('marketing.contact')])>Contact</a>
        </nav>

        <div class="flex shrink-0 items-center gap-3">
            <a href="{{ route('marketing.demo') }}" class="tp-btn-primary hidden sm:inline-flex">
                Request a demo
            </a>
            <button
                type="button"
                class="inline-flex rounded-lg border border-tp-border p-2.5 text-tp-ink lg:hidden"
                aria-label="Open menu"
                aria-expanded="false"
                x-bind:aria-expanded="open"
                x-bind:aria-label="open ? 'Close menu' : 'Open menu'"
                @click="open = !open"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" x-show="!open" d="M4 6h16M4 12h16M4 18h16" />
                    <path stroke-linecap="round" x-show="open" d="M6 6l12 12M6 18L18 6" />
                </svg>
            </button>
        </div>
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition
        class="max-h-[calc(100dvh-4.5rem)] overflow-y-auto border-t border-tp-border bg-tp-surface px-4 py-4 lg:hidden"
    >
        <nav class="flex flex-col gap-1 text-sm font-medium text-tp-muted" aria-label="Mobile">
            <a href="{{ route('marketing.features') }}" class="rounded-lg px-3 py-2.5 hover:bg-tp-canvas" @click="open = false">Features</a>
            <a href="{{ route('marketing.integrations.index') }}" class="rounded-lg px-3 py-2.5 hover:bg-tp-canvas" @click="open = false">Integrations</a>
            <a href="{{ route('marketing.pricing') }}" class="rounded-lg px-3 py-2.5 hover:bg-tp-canvas" @click="open = false">Pricing</a>

            <details class="group rounded-lg border border-tp-border">
                <summary class="cursor-pointer list-none px-3 py-2.5 marker:content-none [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center justify-between">
                        Industries
                        <svg class="h-4 w-4 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" d="M6 9l6 6 6-6" />
                        </svg>
                    </span>
                </summary>
                <div class="space-y-1 border-t border-tp-border px-3 py-2">
                    <a href="{{ route('marketing.solutions.manufacturers') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Drug manufacturers</a>
                    <a href="{{ route('marketing.solutions.wholesalers') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Drug wholesalers</a>
                    <a href="{{ route('marketing.solutions.3pl') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">3PL &amp; logistics</a>
                    <a href="{{ route('marketing.solutions.prepackagers') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Prepackagers</a>
                    <a href="{{ route('marketing.solutions.pharmacies') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Pharmacies</a>
                    <a href="{{ route('marketing.solutions.buying-groups') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Buying groups</a>
                    <a href="{{ route('marketing.solutions.dental-medical') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Dental &amp; medical supply</a>
                </div>
            </details>

            <details class="group rounded-lg border border-tp-border">
                <summary class="cursor-pointer list-none px-3 py-2.5 marker:content-none [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center justify-between">
                        Resources
                        <svg class="h-4 w-4 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" d="M6 9l6 6 6-6" />
                        </svg>
                    </span>
                </summary>
                <div class="space-y-1 border-t border-tp-border px-3 py-2">
                    <a href="{{ route('marketing.resources.index') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Resources hub</a>
                    <a href="{{ route('marketing.guides.epcis-vs-asn') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">EPCIS vs ASN guide</a>
                    <a href="{{ route('marketing.glossary') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">DSCSA glossary</a>
                    <a href="{{ route('marketing.compare.checklist') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Provider checklist</a>
                    <a href="{{ route('marketing.compare.index') }}" class="block rounded-lg py-2 pl-2 hover:bg-tp-canvas" @click="open = false">Compare providers</a>
                </div>
            </details>

            <a href="{{ route('marketing.about') }}" class="rounded-lg px-3 py-2.5 hover:bg-tp-canvas" @click="open = false">About</a>
            <a href="{{ route('marketing.contact') }}" class="rounded-lg px-3 py-2.5 hover:bg-tp-canvas" @click="open = false">Contact</a>
            <a href="{{ route('marketing.demo') }}" class="tp-btn-primary mt-3 w-full justify-center" @click="open = false">Request a demo</a>
        </nav>
    </div>
</header>
