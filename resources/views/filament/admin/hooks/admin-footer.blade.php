@php
    use App\Filament\Admin\Pages\PlatformConnections;
    use App\Support\Marketing\LegalDocumentUrls;
    use Filament\Facades\Filament;
    use MKWebDesign\FilamentWatchdog\Pages\SecurityDashboard;

    $supportEmail = (string) config('tracepharma.platform_support_email', 'support@tracepharma.io');
    $version = (string) config('tracepharma.app_version');
    $environment = (string) app()->environment();

    $adminHelpUrl = null;
    try {
        $adminHelpUrl = Filament::getPanel('admin-knowledge-base')->getUrl();
    } catch (\Throwable) {
        $adminHelpUrl = null;
    }

    $platformConnectionsUrl = null;
    try {
        $platformConnectionsUrl = PlatformConnections::getUrl(panel: 'admin');
    } catch (\Throwable) {
        $platformConnectionsUrl = null;
    }

    $securityUrl = null;
    if (class_exists(SecurityDashboard::class)) {
        try {
            $securityUrl = SecurityDashboard::getUrl(panel: 'admin');
        } catch (\Throwable) {
            $securityUrl = null;
        }
    }

    $primary = array_values(array_filter([
        $adminHelpUrl !== null ? ['title' => 'Admin help', 'url' => $adminHelpUrl, 'target' => '_blank'] : null,
        $platformConnectionsUrl !== null ? ['title' => 'Platform connections', 'url' => $platformConnectionsUrl, 'target' => '_self'] : null,
        $securityUrl !== null ? ['title' => 'Security', 'url' => $securityUrl, 'target' => '_self'] : null,
        ['title' => 'Support', 'url' => 'mailto:'.$supportEmail, 'target' => '_self'],
    ]));

    $legal = [
        ['title' => 'Terms of Service', 'url' => LegalDocumentUrls::termsUrl(), 'target' => '_blank'],
        ['title' => 'Privacy Policy', 'url' => LegalDocumentUrls::privacyUrl(), 'target' => '_blank'],
    ];
@endphp

<footer class="tp-tenant-footer">
    <div class="tp-tenant-footer__inner">
        @if ($primary !== [])
            <nav class="tp-tenant-footer__nav tp-tenant-footer__nav--primary" aria-label="Admin help and ops">
                @foreach ($primary as $item)
                    <a
                        href="{{ $item['url'] }}"
                        @if (($item['target'] ?? '_self') !== '_self')
                            target="{{ $item['target'] }}"
                            rel="noopener noreferrer"
                        @endif
                        class="tp-tenant-footer__link"
                    >
                        {{ $item['title'] }}
                    </a>
                @endforeach
            </nav>
        @endif

        <nav class="tp-tenant-footer__nav tp-tenant-footer__nav--legal" aria-label="Legal">
            @foreach ($legal as $item)
                <a
                    href="{{ $item['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="tp-tenant-footer__link tp-tenant-footer__link--muted"
                >
                    {{ $item['title'] }}
                </a>
                @if (! $loop->last)
                    <span class="tp-tenant-footer__sep" aria-hidden="true">·</span>
                @endif
            @endforeach
        </nav>

        <p class="tp-tenant-footer__copy">
            © 2026 Vatengi Systems LLC. TracePharma is a product of Vatengi Systems LLC · {{ $version }} · {{ $environment }}
        </p>
    </div>
</footer>
