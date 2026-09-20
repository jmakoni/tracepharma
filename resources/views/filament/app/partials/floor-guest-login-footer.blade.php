@php
    use App\Support\Marketing\LegalDocumentUrls;
@endphp

<footer class="tp-floor-guest-login-footer text-center text-sm text-base-content/70" aria-label="Legal documents">
    <nav>
        <a
            href="{{ LegalDocumentUrls::termsUrl() }}"
            class="link link-hover"
            target="_blank"
            rel="noopener noreferrer"
        >Terms of Service</a>
        <span class="mx-2" aria-hidden="true">·</span>
        <a
            href="{{ LegalDocumentUrls::privacyUrl() }}"
            class="link link-hover"
            target="_blank"
            rel="noopener noreferrer"
        >Privacy Policy</a>
    </nav>
    <p class="mt-3 text-xs opacity-70">{{ config('tracepharma.app_version') }}</p>
</footer>
