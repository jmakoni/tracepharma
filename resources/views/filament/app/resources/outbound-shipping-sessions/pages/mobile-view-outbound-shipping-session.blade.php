<x-filament-panels::page>
    @assets
        <script src="{{ asset('js/tp-floor-receive.js') }}"></script>
    @endassets

    <x-scan-flash />

    <div class="tp-floor-ship__layout-switch">
        @include('filament.app.partials.ship-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopShipUrl(),
            'floorUrl' => $this->floorShipUrl(),
        ])
    </div>

    @php
        $cookieName = \App\Support\Shipping\ShipLayout::COOKIE;
        $desktopUrl = $this->desktopShipUrl();
        $recentLines = $this->recentScanLines();
        $cartCount = $this->cartBadgeCount();
        $recentCaption = $this->recentScansCaption();
        $isCompleted = $this->isCompleted();
        $isCancelled = $this->isCancelled();
    @endphp

    <div
        class="tp-floor-receive tp-floor-ship"
        x-data="tpFloorReceive(@js(['libraryUrl' => asset('vendor/html5-qrcode/html5-qrcode.min.js')]))"
        x-on:destroy="stopCamera()"
        @keydown.escape.window="if (cameraOn) { stopCamera() } else if (cartOpen) { closeCart() }"
    >
        <header class="tp-floor-receive__sticky-header">
            <div
                class="tp-floor-receive__progress-stats stats stats-horizontal bg-base-200 shadow"
                aria-label="Confirmed {{ $this->confirmedCount() }}"
                aria-live="polite"
            >
                <div class="stat">
                    <div class="stat-title">Confirmed</div>
                    <div class="stat-value text-2xl">{{ $this->confirmedCount() }}</div>
                </div>
            </div>

            <div
                class="tp-floor-receive__menu"
                x-data="{ open: false }"
                @keydown.escape.window="open = false"
            >
                <button
                    type="button"
                    class="tp-floor-receive__menu-btn"
                    aria-label="Ship order menu"
                    aria-haspopup="true"
                    :aria-expanded="open"
                    @click="open = !open"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-6" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10.5 6a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm0 6a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm0 6a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div
                    x-cloak
                    x-show="open"
                    x-transition
                    @click.outside="open = false"
                    class="tp-floor-receive__menu-panel"
                    role="menu"
                >
                    <a
                        href="{{ $this->scanOutDeskUrl() }}"
                        class="tp-floor-receive__menu-item"
                        role="menuitem"
                    >Customer &amp; send on Scan Out</a>
                    <a
                        href="{{ $desktopUrl }}"
                        class="tp-floor-receive__menu-item"
                        role="menuitem"
                        onclick="document.cookie='{{ $cookieName }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
                    >Open desktop ship order</a>
                </div>
            </div>
        </header>

        <p class="px-4 text-sm font-medium text-base-content/80">{{ $this->routeDisplayLabel() }}</p>

        @if ($isCompleted)
            <div class="tp-floor-receive__complete">
                <div class="tp-floor-receive__complete-title">{{ $this->shipCompleteCopy()['title'] }}</div>
                <p class="tp-floor-receive__complete-body">
                    {{ $this->shipCompleteCopy()['body'] }}
                </p>
                <a href="{{ $this->shippingListUrl() }}" class="tp-floor-receive__cancel-btn tp-floor-receive__complete-exit">
                    Back to ship orders
                </a>
            </div>
        @elseif ($isCancelled)
            <div class="tp-floor-receive__complete">
                <div class="tp-floor-receive__complete-title">Ship order cancelled</div>
                <p class="tp-floor-receive__complete-body">
                    This order is closed. Open the desktop view for audit details.
                </p>
                <a href="{{ $this->shippingListUrl() }}" class="tp-floor-receive__cancel-btn tp-floor-receive__complete-exit">
                    Back to ship orders
                </a>
            </div>
        @else
            <div class="tp-floor-receive__stage">
                <form
                    wire:submit.prevent="stageScan"
                    x-init="$nextTick(() => $refs.scanInput?.focus())"
                    x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                    class="tp-floor-receive__scan-form"
                >
                    <div class="tp-floor-receive__scan-field">
                        <input
                            id="floor-scan-input"
                            type="text"
                            wire:model.live.blur="scan"
                            x-ref="scanInput"
                            x-on:keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"
                            autocomplete="off"
                            autofocus
                            class="tp-floor-receive__scan-input"
                            placeholder="Scan barcode"
                            aria-label="Scan to confirm ship order"
                        />
                    </div>

                    <button
                        type="button"
                        class="tp-floor-receive__camera-btn"
                        x-ref="cameraBtn"
                        :aria-label="cameraOn ? 'Close camera' : 'Open camera scanner'"
                        :aria-pressed="cameraOn ? 'true' : 'false'"
                        x-bind:disabled="starting"
                        x-on:click="toggleCamera()"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                        </svg>
                        <span class="tp-floor-receive__camera-btn-label">Camera</span>
                    </button>
                </form>

                <p x-show="cameraError" x-cloak x-text="cameraError" class="tp-floor-receive__camera-error" role="alert"></p>

                @if ($this->lastScanMessage)
                    <div
                        role="status"
                        aria-live="{{ $this->lastScanTone === 'error' ? 'assertive' : 'polite' }}"
                        @class([
                            'tp-floor-receive__status',
                            'tp-floor-receive__status--ok' => $this->lastScanTone === 'ok',
                            'tp-floor-receive__status--warn' => $this->lastScanTone === 'warn',
                            'tp-floor-receive__status--error' => $this->lastScanTone === 'error',
                        ])
                    >
                        <span class="tp-floor-receive__status-prefix" aria-hidden="true">
                            @if ($this->lastScanTone === 'ok') OK
                            @elseif ($this->lastScanTone === 'warn') Check
                            @else Error
                            @endif
                        </span>
                        <span class="tp-floor-receive__status-title">{{ $this->lastScanMessage }}</span>
                        @if ($this->lastScanDetail)
                            <span class="tp-floor-receive__status-detail">{{ $this->lastScanDetail }}</span>
                        @endif
                    </div>
                @endif

                @if ($this->confirmedCount() > 0)
                    <a
                        href="{{ $desktopUrl }}"
                        class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--ready tp-floor-receive__stage-complete"
                        onclick="document.cookie='{{ $cookieName }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
                    >
                        Customer &amp; send
                    </a>
                @endif
            </div>
        @endif

        @if ($this->canScan())
            <button
                type="button"
                class="tp-floor-receive__cart-fab"
                x-ref="cartFab"
                aria-label="Open scanned items, {{ $cartCount }}"
                x-on:click="openCart()"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                </svg>
                @if ($cartCount > 0)
                    <span class="tp-floor-receive__cart-count" aria-live="polite">{{ $cartCount }}</span>
                @endif
            </button>
        @endif

        <div
            x-show="cameraOn"
            x-cloak
            class="tp-floor-receive__camera-overlay"
            role="dialog"
            aria-modal="true"
            aria-label="Camera scanner"
            @keydown="trapTab($event, $el)"
        >
            <div class="tp-floor-receive__camera-overlay-bar">
                <span>Align barcode</span>
                <button
                    type="button"
                    class="tp-floor-receive__camera-close"
                    x-ref="cameraClose"
                    x-on:click="stopCamera()"
                >
                    Close
                </button>
            </div>
            <div wire:ignore class="tp-floor-receive__camera-host">
                <div id="tp-floor-qr-reader" class="tp-floor-receive__camera"></div>
            </div>
        </div>

        @if ($this->canScan())
            <div
                x-show="cartOpen"
                x-cloak
                class="tp-floor-receive__sheet-backdrop"
                x-on:click="closeCart()"
                x-transition.opacity
            ></div>

            <div
                x-show="cartOpen"
                x-cloak
                class="tp-floor-receive__sheet"
                role="dialog"
                aria-modal="true"
                aria-label="Recent scans"
                x-transition:enter="tp-floor-receive__sheet-enter"
                x-transition:enter-start="tp-floor-receive__sheet-enter-start"
                x-transition:enter-end="tp-floor-receive__sheet-enter-end"
                x-transition:leave="tp-floor-receive__sheet-leave"
                x-transition:leave-start="tp-floor-receive__sheet-leave-start"
                x-transition:leave-end="tp-floor-receive__sheet-leave-end"
                @keydown="trapTab($event, $el)"
            >
                <div class="tp-floor-receive__sheet-header">
                    <h2 class="tp-floor-receive__sheet-title">Recent scans</h2>
                    <button
                        type="button"
                        class="tp-floor-receive__sheet-close"
                        x-ref="sheetClose"
                        x-on:click="closeCart()"
                    >
                        Close
                    </button>
                </div>

                <div class="tp-floor-receive__sheet-progress" aria-live="polite">
                    <div class="tp-floor-receive__sheet-progress-row">
                        <span>Confirmed</span>
                        <strong class="tp-scan-qty">{{ $this->confirmedCount() }}</strong>
                    </div>
                </div>

                <section class="tp-floor-receive__recent" aria-label="Recent scans">
                    @if ($recentCaption)
                        <p class="tp-floor-receive__recent-caption">{{ $recentCaption }}</p>
                    @endif

                    @forelse ($recentLines as $line)
                        <div class="tp-floor-receive__recent-row">
                            <span class="tp-floor-receive__recent-id font-mono">{{ $this->recentScanLineLabel($line) }}</span>
                            <span class="tp-floor-receive__recent-meta">
                                {{ ucfirst((string) $line->status) }}
                            </span>
                        </div>
                    @empty
                        <p class="tp-floor-receive__recent-empty">Scanned items will appear here</p>
                    @endforelse
                </section>

                <div class="tp-floor-receive__sheet-actions">
                    @if ($this->confirmedCount() > 0)
                        <a
                            href="{{ $desktopUrl }}"
                            class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--ready"
                            onclick="document.cookie='{{ $cookieName }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
                        >
                            Customer &amp; send
                        </a>
                    @else
                        <p class="tp-floor-receive__complete-reason">
                            Scan at least one item, then finish customer and send on desktop.
                        </p>
                    @endif

                    <a href="{{ $this->shippingListUrl() }}" class="tp-floor-receive__cancel-btn">
                        Back to ship orders
                    </a>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
