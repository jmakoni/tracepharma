@php
    $isFloorCompact = request()->routeIs([
        'filament.app.resources.receiving-sessions.floor',
        'filament.app.resources.transferring-sessions.floor',
        'filament.app.resources.outbound-shipping-sessions.floor',
        'filament.app.pages.pack.floor',
        'filament.app.pages.verify-product.floor',
        'filament.app.pages.unpack.floor',
        'filament.app.pages.break-pack.floor',
        'filament.app.pages.floor',
    ]) || (function_exists('floorShell') && floorShell() && request()->path() === '/');

    $isGuest = auth()->guest();
    $guestHiddenPrimary = ['Settings', 'My profile', 'Getting started'];

    $items = \App\Support\Menus\TenantFooterMenu::items();

    $legalTitles = ['Legal', 'Terms of Service', 'Privacy Policy', 'Support'];
    $primary = [];
    $legal = [];

    foreach ($items as $item) {
        if (in_array($item['title'], $legalTitles, true)) {
            $legal[] = $item;
        } elseif ($isGuest && in_array($item['title'], $guestHiddenPrimary, true)) {
            continue;
        } else {
            $primary[] = $item;
        }
    }

    $hasFooterBody = $primary !== [] || $legal !== [];
    $copy = '© 2026 Vatengi Systems LLC. TracePharma is a product of Vatengi Systems LLC · '.config('tracepharma.app_version');
@endphp

@if ($isFloorCompact)
    <footer class="tp-tenant-footer tp-tenant-footer--floor-compact">
        <div class="tp-tenant-footer__inner">
            <p class="tp-tenant-footer__copy">{{ $copy }}</p>
        </div>
    </footer>
@elseif ($hasFooterBody || $isGuest)
    <footer @class([
        'tp-tenant-footer',
        'tp-tenant-footer--guest' => $isGuest,
    ])>
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

            <p class="tp-tenant-footer__copy">{{ $copy }}</p>
        </div>
    </footer>
@endif
