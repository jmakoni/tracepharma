@php
    $items = \App\Support\Menus\TenantFooterMenu::items();

    $legalTitles = ['Terms of Service', 'Privacy Policy', 'Support'];
    $primary = [];
    $legal = [];

    foreach ($items as $item) {
        if (in_array($item['title'], $legalTitles, true)) {
            $legal[] = $item;
        } else {
            $primary[] = $item;
        }
    }
@endphp

@if ($items !== [])
    <footer class="tp-tenant-footer">
        <div class="tp-tenant-footer__inner">
            @if ($primary !== [])
                <nav class="tp-tenant-footer__nav tp-tenant-footer__nav--primary" aria-label="Account and help">
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

            @if ($legal !== [])
                <nav class="tp-tenant-footer__nav tp-tenant-footer__nav--legal" aria-label="Legal and support">
                    @foreach ($legal as $item)
                        <a
                            href="{{ $item['url'] }}"
                            @if (($item['target'] ?? '_self') !== '_self')
                                target="{{ $item['target'] }}"
                                rel="noopener noreferrer"
                            @endif
                            class="tp-tenant-footer__link tp-tenant-footer__link--muted"
                        >
                            {{ $item['title'] }}
                        </a>
                        @if (! $loop->last)
                            <span class="tp-tenant-footer__sep" aria-hidden="true">·</span>
                        @endif
                    @endforeach
                </nav>
            @endif

            <p class="tp-tenant-footer__copy">© 2026 TracePharma</p>
        </div>
    </footer>
@endif
