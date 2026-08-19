@php
    use App\Support\Marketing\LegalDocumentUrls;
@endphp

<nav class="mt-6 text-center text-sm opacity-70" aria-label="Legal documents">
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
