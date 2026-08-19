@props([
    'showUserAcceptance' => false,
    'user' => null,
])

@php
    use App\Support\Marketing\LegalDocumentUrls;
    use App\Support\Marketing\PrivacyPolicy;
    use App\Support\Marketing\TermsOfService;

    $currentTermsVersion = TermsOfService::version();
    $currentPrivacyVersion = PrivacyPolicy::version();
    $hasAcceptedCurrent = $showUserAcceptance
        && $user !== null
        && $user->terms_version === $currentTermsVersion
        && $user->privacy_version === $currentPrivacyVersion;
@endphp

<div {{ $attributes->class(['mx-auto max-w-2xl space-y-8']) }}>
    <div class="flex flex-col items-center text-center">
        <img
            src="/images/brand/logo.svg"
            alt="{{ TermsOfService::productName() }}"
            class="h-16 w-auto dark:hidden sm:h-20"
        >
        <img
            src="/images/brand/logo-dark.svg"
            alt="{{ TermsOfService::productName() }}"
            class="hidden h-16 w-auto dark:block sm:h-20"
        >
        <p class="mt-6 max-w-lg text-sm leading-relaxed text-tp-muted dark:text-tp-dark-muted">
            {{ TermsOfService::copyrightNotice() }}
        </p>
    </div>

    <div class="space-y-4">
        <x-ui.section-header
            title="Legal documents"
            description="Current Terms of Service and Privacy Policy for {{ TermsOfService::legalEntityName() }}."
        />

        <x-ui.card>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1">
                    <h3 class="text-base font-semibold text-tp-ink dark:text-white">Terms of Service</h3>
                    <p class="text-sm text-tp-muted dark:text-tp-dark-muted">
                        <span class="font-medium text-tp-ink dark:text-white">Version:</span> {{ $currentTermsVersion }}
                        <span class="mx-2 text-tp-border dark:text-white/20" aria-hidden="true">·</span>
                        <span class="font-medium text-tp-ink dark:text-white">Effective:</span> {{ TermsOfService::effectiveDate() }}
                    </p>
                </div>
                <a
                    href="{{ LegalDocumentUrls::termsUrl() }}"
                    class="inline-flex shrink-0 items-center text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-400"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Read full Terms →
                </a>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1">
                    <h3 class="text-base font-semibold text-tp-ink dark:text-white">Privacy Policy</h3>
                    <p class="text-sm text-tp-muted dark:text-tp-dark-muted">
                        <span class="font-medium text-tp-ink dark:text-white">Version:</span> {{ $currentPrivacyVersion }}
                        <span class="mx-2 text-tp-border dark:text-white/20" aria-hidden="true">·</span>
                        <span class="font-medium text-tp-ink dark:text-white">Effective:</span> {{ PrivacyPolicy::effectiveDate() }}
                    </p>
                </div>
                <a
                    href="{{ LegalDocumentUrls::privacyUrl() }}"
                    class="inline-flex shrink-0 items-center text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-400"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Read full Privacy Policy →
                </a>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card>
        <div class="space-y-1">
            <h3 class="text-base font-semibold text-tp-ink dark:text-white">Application version</h3>
            <p class="text-sm text-tp-muted dark:text-tp-dark-muted">
                TracePharma release <span class="font-mono font-medium text-tp-ink dark:text-white">{{ config('tracepharma.app_version') }}</span>
            </p>
        </div>
    </x-ui.card>

    @if ($showUserAcceptance && $user !== null)
        <div class="space-y-4">
            <x-ui.section-header
                title="Your acceptance"
                description="Legal acceptance recorded for {{ $user->email }}."
            />

            <x-ui.card>
                @if (! $hasAcceptedCurrent)
                    <div class="mb-4">
                        <x-ui.status variant="warning">
                            You have not accepted the current Terms and Privacy Policy versions.
                        </x-ui.status>
                    </div>
                @endif

                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-medium text-tp-muted dark:text-tp-dark-muted">Terms accepted</dt>
                        <dd class="mt-1 text-tp-ink dark:text-white">
                            {{ $user->terms_accepted_at?->timezone(config('app.timezone'))->format('M j, Y g:i A T') ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-tp-muted dark:text-tp-dark-muted">Terms version</dt>
                        <dd class="mt-1 font-mono text-tp-ink dark:text-white">
                            {{ $user->terms_version ?? '—' }}
                            @if ($user->terms_version !== null && $user->terms_version !== $currentTermsVersion)
                                <span class="ml-2 text-tp-warning">(current: {{ $currentTermsVersion }})</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-tp-muted dark:text-tp-dark-muted">Privacy accepted</dt>
                        <dd class="mt-1 text-tp-ink dark:text-white">
                            {{ $user->privacy_accepted_at?->timezone(config('app.timezone'))->format('M j, Y g:i A T') ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-tp-muted dark:text-tp-dark-muted">Privacy version</dt>
                        <dd class="mt-1 font-mono text-tp-ink dark:text-white">
                            {{ $user->privacy_version ?? '—' }}
                            @if ($user->privacy_version !== null && $user->privacy_version !== $currentPrivacyVersion)
                                <span class="ml-2 text-tp-warning">(current: {{ $currentPrivacyVersion }})</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>
    @endif
</div>
