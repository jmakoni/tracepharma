@extends('marketing.layout')

@section('title', 'Get started — TracePharma')
@section('meta_description', 'Apply for a TracePharma tenant workspace. Submit your organization details, accept our Terms and Privacy Policy, and our team will provision your DSCSA traceability environment.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Customer onboarding"
        title="Get started with TracePharma"
        description="Ready to move beyond a demo? Submit your organization details and accept our Terms of Service and Privacy Policy. Our team reviews each request and provisions a tenant workspace tuned to your operating profile."
    />

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-5">
            <div class="lg:col-span-3">
                @if (session('onboarding_submitted'))
                    <div class="tp-alert--info p-6" role="status">
                        <h2 class="text-lg font-semibold text-tp-ink">Thank you—we received your application.</h2>
                        <p class="mt-2 text-sm leading-relaxed text-tp-muted">
                            Our team will review your submission and email you at the address you provided with next steps, including tenant access once approved.
                        </p>
                        <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-tp-primary-700">What happens next</h3>
                        <ol class="mt-4 space-y-4 text-sm leading-relaxed text-tp-muted">
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tp-primary-100 text-xs font-semibold text-tp-primary-700">1</span>
                                <span><strong class="text-tp-ink">Application review</strong> — We verify your organization type, GLN if provided, and contracting readiness.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tp-primary-100 text-xs font-semibold text-tp-primary-700">2</span>
                                <span><strong class="text-tp-ink">Tenant provisioning</strong> — After approval, we create your stage and production tenant hosts with the right profile and navigation.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-tp-primary-100 text-xs font-semibold text-tp-primary-700">3</span>
                                <span><strong class="text-tp-ink">First login</strong> — Your owner account signs in, accepts the current Terms and Privacy Policy per user, and completes in-app DSCSA setup.</span>
                            </li>
                        </ol>
                        <p class="mt-6 text-sm leading-relaxed text-tp-muted">
                            Just exploring? You can also
                            <a href="{{ route('marketing.demo') }}" class="font-semibold text-tp-link hover:text-tp-primary-600">request a demo</a>
                            without committing to a tenant.
                        </p>
                    </div>
                @endif

                <form
                    action="{{ url('/get-started') }}"
                    method="POST"
                    class="tp-card mt-6 space-y-6 border-tp-border p-6 sm:p-8 @if(session('onboarding_submitted')) hidden @endif"
                >
                    @csrf

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="legal_company_name" class="text-tp-label block">Legal company name</label>
                            <input
                                type="text"
                                name="legal_company_name"
                                id="legal_company_name"
                                value="{{ old('legal_company_name') }}"
                                required
                                autocomplete="organization"
                                class="tp-input mt-2"
                            >
                            @error('legal_company_name')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="company_display_name" class="text-tp-label block">Organization display name</label>
                            <input
                                type="text"
                                name="company_display_name"
                                id="company_display_name"
                                value="{{ old('company_display_name') }}"
                                required
                                class="tp-input mt-2"
                            >
                            @error('company_display_name')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="contact_name" class="text-tp-label block">Primary contact name</label>
                            <input
                                type="text"
                                name="contact_name"
                                id="contact_name"
                                value="{{ old('contact_name') }}"
                                required
                                autocomplete="name"
                                class="tp-input mt-2"
                            >
                            @error('contact_name')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="contact_email" class="text-tp-label block">Work email</label>
                            <input
                                type="email"
                                name="contact_email"
                                id="contact_email"
                                value="{{ old('contact_email') }}"
                                required
                                autocomplete="email"
                                class="tp-input mt-2"
                            >
                            @error('contact_email')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="contact_phone" class="text-tp-label block">Phone <span class="font-normal text-tp-muted">(optional)</span></label>
                            <input
                                type="tel"
                                name="contact_phone"
                                id="contact_phone"
                                value="{{ old('contact_phone') }}"
                                autocomplete="tel"
                                class="tp-input mt-2"
                            >
                            @error('contact_phone')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="contact_role" class="text-tp-label block">Your role <span class="font-normal text-tp-muted">(optional)</span></label>
                            <input
                                type="text"
                                name="contact_role"
                                id="contact_role"
                                value="{{ old('contact_role') }}"
                                placeholder="Owner, compliance lead, IT director…"
                                class="tp-input mt-2"
                            >
                            @error('contact_role')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="organization_type" class="text-tp-label block">Organization type</label>
                            <select
                                name="organization_type"
                                id="organization_type"
                                required
                                class="tp-select mt-2"
                            >
                                <option value="">Select…</option>
                                @foreach (\App\Support\CustomerOnboarding\OrganizationTypeMapper::options() as $value => $label)
                                    <option value="{{ $value }}" @selected(old('organization_type') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('organization_type')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="gln" class="text-tp-label block">Organization GLN <span class="font-normal text-tp-muted">(optional)</span></label>
                            <input
                                type="text"
                                name="gln"
                                id="gln"
                                value="{{ old('gln') }}"
                                inputmode="numeric"
                                pattern="[0-9]{13}"
                                maxlength="13"
                                placeholder="13-digit GS1 GLN"
                                class="tp-input mt-2 font-mono"
                            >
                            @error('gln')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label for="message" class="text-tp-label block">Additional context <span class="font-normal text-tp-muted">(optional)</span></label>
                        <textarea
                            name="message"
                            id="message"
                            rows="4"
                            placeholder="Trading partners, go-live timeline, current DSCSA tool, integration needs…"
                            class="tp-textarea mt-2"
                        >{{ old('message') }}</textarea>
                        @error('message')<p class="mt-1 text-sm text-tp-danger">{{ $message }}</p>@enderror
                    </div>

                    <div class="space-y-3 rounded-lg border border-tp-border bg-tp-canvas p-4">
                        <label class="flex items-start gap-3 text-sm text-tp-body">
                            <input
                                type="checkbox"
                                name="accept_terms"
                                value="1"
                                @checked(old('accept_terms'))
                                required
                                class="tp-checkbox mt-1"
                            >
                            <span>
                                I agree to the
                                <a href="{{ route('marketing.tos') }}" class="font-semibold text-tp-link hover:text-tp-primary-600" target="_blank" rel="noopener noreferrer">Terms of Service</a>
                                (version {{ \App\Support\Marketing\TermsOfService::version() }}).
                            </span>
                        </label>
                        @error('accept_terms')<p class="text-sm text-tp-danger">{{ $message }}</p>@enderror

                        <label class="flex items-start gap-3 text-sm text-tp-body">
                            <input
                                type="checkbox"
                                name="accept_privacy"
                                value="1"
                                @checked(old('accept_privacy'))
                                required
                                class="tp-checkbox mt-1"
                            >
                            <span>
                                I agree to the
                                <a href="{{ route('marketing.privacy') }}" class="font-semibold text-tp-link hover:text-tp-primary-600" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
                                (version {{ \App\Support\Marketing\PrivacyPolicy::version() }}).
                            </span>
                        </label>
                        @error('accept_privacy')<p class="text-sm text-tp-danger">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="tp-btn-primary w-full sm:w-auto">
                        Submit application
                    </button>
                    <p class="text-tp-caption text-xs leading-relaxed">
                        By submitting, you authorize TracePharma to contact you about provisioning. This is separate from a demo request—see
                        <a href="{{ route('marketing.demo') }}" class="text-tp-link hover:text-tp-primary-600">request a demo</a>
                        if you only need a product walkthrough.
                    </p>
                </form>
            </div>

            <aside class="lg:col-span-2">
                <div class="tp-card border-tp-border p-6">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-tp-primary-700">Demo vs. customer onboarding</h2>
                    <ul class="mt-4 space-y-4 text-sm leading-relaxed text-tp-muted">
                        <li><strong class="text-tp-ink">Demo request</strong> — Sales walkthrough; no tenant commitment.</li>
                        <li><strong class="text-tp-ink">Get started</strong> — Contracting path; legal acceptance recorded; admin provisions your tenant after review.</li>
                        <li>Each user accepts current Terms and Privacy Policy on first login.</li>
                        <li>In-app Getting Started wizard configures GLN, partners, and inbound channels after access is granted.</li>
                    </ul>
                    <a href="{{ route('marketing.demo') }}" class="mt-6 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600">
                        Request a demo instead →
                    </a>
                </div>
                <div class="tp-card-accent mt-6 border-tp-primary-200 p-6 text-sm">
                    <p class="font-semibold text-tp-ink">Already provisioned?</p>
                    <p class="mt-2 leading-relaxed text-tp-muted">Sign in at your tenant subdomain (for example, <code class="rounded bg-tp-canvas px-1 py-0.5 font-mono text-xs text-tp-ink">https://your-company.prod.tracepharma.io</code>).</p>
                </div>
            </aside>
        </div>
    </section>
@endsection
