@extends('marketing.layout')

@section('title', 'Request a demo — TracePharma')
@section('meta_description', 'Request a TracePharma demo for L4 DSCSA traceability—manufacturers, wholesalers, 3PLs, and dispensers.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Get started"
        title="Request a demo"
        description="Tell us your operating profile and we'll schedule a 30–45 minute walkthrough—outbound ship, receive-to-ship, principal ops, or dispenser verification. No obligation; pricing follows scoping."
    />

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-5">
            <div class="lg:col-span-3">
                @if (session('demo_submitted'))
                    <div class="tp-alert--info p-6" role="status">
                        <h2 class="text-lg font-semibold text-tp-ink">Thank you—we received your request.</h2>
                        <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                            Our team will reach out at the email you provided. Include your operating profile and timeline if you need a faster response.
                        </p>
                        <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-tp-primary-700">What happens next</h3>
                        <ol class="mt-4 space-y-4 text-sm leading-relaxed text-tp-muted">
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tp-primary-100 text-xs font-semibold text-tp-primary-700">1</span>
                                <span><strong class="text-tp-ink">Demo scheduling</strong> — We confirm your profile (manufacturer, wholesaler, 3PL, dispenser, etc.) and book a 30–45 minute walkthrough.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tp-primary-100 text-xs font-semibold text-tp-primary-700">2</span>
                                <span><strong class="text-tp-ink">Tenant provisioning</strong> — After the demo, your workspace is tuned to your operating profile with the right navigation and feature gates.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tp-primary-100 text-xs font-semibold text-tp-primary-700">3</span>
                                <span><strong class="text-tp-ink">In-app onboarding</strong> — Set your organization GLN, add your first trading partner (or 3PL principal), configure inbound channels, and run a test receive.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tp-primary-100 text-xs font-semibold text-tp-primary-700">4</span>
                                <span><strong class="text-tp-ink">Scoping &amp; proposal</strong> — We document partner count, sites, and modules, then send packaging options. See <a href="{{ route('marketing.pricing') }}" class="font-semibold text-tp-link hover:text-tp-primary-600">how we quote</a>.</span>
                            </li>
                        </ol>
                        @if (session('demo_solution_url') && session('demo_solution_label'))
                            <p class="mt-6 text-sm leading-relaxed text-tp-muted">
                                While you wait, explore our
                                <a href="{{ session('demo_solution_url') }}" class="font-semibold text-tp-link hover:text-tp-primary-600">{{ session('demo_solution_label') }} solution overview →</a>
                            </p>
                        @endif
                    </div>
                @endif

                <form
                    action="{{ url('/demo') }}"
                    method="POST"
                    class="tp-card mt-6 space-y-6 border-tp-border p-6 sm:p-8 @if(session('demo_submitted')) hidden @endif"
                >
                    @csrf

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="name" class="text-tp-label block">Name</label>
                            <input
                                type="text"
                                name="name"
                                id="name"
                                value="{{ old('name') }}"
                                required
                                autocomplete="name"
                                class="tp-input mt-2"
                            >
                            @error('name')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="email" class="text-tp-label block">Work email</label>
                            <input
                                type="email"
                                name="email"
                                id="email"
                                value="{{ old('email') }}"
                                required
                                autocomplete="email"
                                class="tp-input mt-2"
                            >
                            @error('email')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="company" class="text-tp-label block">Organization name</label>
                            <input
                                type="text"
                                name="company"
                                id="company"
                                value="{{ old('company') }}"
                                required
                                autocomplete="organization"
                                class="tp-input mt-2"
                            >
                            @error('company')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="text-tp-label block">Phone <span class="font-normal text-tp-muted">(optional)</span></label>
                            <input
                                type="tel"
                                name="phone"
                                id="phone"
                                value="{{ old('phone') }}"
                                autocomplete="tel"
                                class="tp-input mt-2"
                            >
                            @error('phone')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="role" class="text-tp-label block">Your role <span class="font-normal text-tp-muted">(optional)</span></label>
                            <input
                                type="text"
                                name="role"
                                id="role"
                                value="{{ old('role') }}"
                                placeholder="Compliance lead, warehouse manager, serialization IT…"
                                class="tp-input mt-2"
                            >
                            @error('role')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="organization_type" class="text-tp-label block">Organization type</label>
                            <select
                                name="organization_type"
                                id="organization_type"
                                class="tp-select mt-2"
                            >
                                <option value="">Select…</option>
                                <option value="independent_pharmacy" @selected(old('organization_type') === 'independent_pharmacy')>Independent pharmacy</option>
                                <option value="hospital_health_system" @selected(old('organization_type') === 'hospital_health_system')>Hospital / health system</option>
                                <option value="wholesaler" @selected(old('organization_type') === 'wholesaler')>Wholesaler / distributor</option>
                                <option value="manufacturer" @selected(old('organization_type') === 'manufacturer')>Manufacturer</option>
                                <option value="logistics_3pl" @selected(old('organization_type') === 'logistics_3pl')>3PL / contract logistics</option>
                                <option value="buying_group" @selected(old('organization_type') === 'buying_group')>Pharmacy buying group</option>
                                <option value="dental_medical" @selected(old('organization_type') === 'dental_medical')>Dental / medical supply</option>
                                <option value="prepackager" @selected(old('organization_type') === 'prepackager')>Prepackager / repackager</option>
                                <option value="other" @selected(old('organization_type') === 'other')>Other</option>
                            </select>
                            @error('organization_type')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label for="message" class="text-tp-label block">What should we focus on? <span class="font-normal text-tp-muted">(optional)</span></label>
                        <textarea
                            name="message"
                            id="message"
                            rows="4"
                            placeholder="Trading partners, current DSCSA tool, go-live timeline, integration needs…"
                            class="tp-textarea mt-2"
                        >{{ old('message') }}</textarea>
                        @error('message')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="tp-btn-primary w-full sm:w-auto">
                        Submit demo request
                    </button>
                    <p class="text-xs leading-relaxed text-tp-muted">We respond by email to schedule your walkthrough. Select your organization type so we tune the demo to your workflows.</p>
                </form>
            </div>

            <aside class="lg:col-span-2">
                <div class="tp-card border-tp-border p-6">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-tp-teal-400">What to expect</h2>
                    <ul class="mt-4 space-y-4 text-sm leading-relaxed text-tp-muted">
                        <li>30–45 minute product walkthrough on a profile-tuned tenant workspace</li>
                        <li>Manufacturers: L3 serial allocation, outbound EPCIS, ACK monitoring</li>
                        <li>Wholesalers &amp; 3PL: receive-to-ship, principals, cross-dock</li>
                        <li>Dispensers: receiving, VRS verification, exceptions, FDA 3911</li>
                        <li>Pricing conversation after we understand your volume and integrations</li>
                    </ul>
                    <a href="{{ route('marketing.solutions.manufacturers') }}" class="mt-6 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                        Manufacturer solution overview →
                    </a>
                </div>
                <div class="tp-card-accent mt-6 border-tp-accent-500/30 p-6 text-sm text-tp-ink">
                    <p class="font-semibold text-tp-ink">Already a customer?</p>
                    <p class="mt-2 leading-relaxed text-tp-muted">Sign in at your tenant subdomain (for example, <code class="rounded bg-tp-canvas px-1 py-0.5 font-mono text-xs text-tp-ink">https://your-company.prod.tracepharma.io</code>).</p>
                </div>
            </aside>
        </div>
    </section>
@endsection