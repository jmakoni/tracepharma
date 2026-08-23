<x-filament-panels::page>
    @assets
        <script src="{{ asset('js/tp-floor-receive.js') }}"></script>
    @endassets

    <x-scan-flash />

    {{-- Auto layout redirect (visible link hidden; menu owns desktop link). --}}
    <div class="tp-floor-receive__layout-switch">
        @include('filament.app.partials.receive-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopReceiveUrl(),
            'floorUrl' => $this->floorReceiveUrl(),
        ])
    </div>

    @php
        $cookieName = \App\Support\Receiving\ReceiveLayout::COOKIE;
        $desktopUrl = $this->desktopReceiveUrl();
        $showUnits = $this->showUnitsProgress();
        $parentTypeLabel = $this->parentTypeLabel();
        $childTypeLabel = $this->childTypeLabel();
        $recentLines = $this->recentScanLines();
        $cartCount = $this->cartBadgeCount();
        $recentCaption = $this->recentScansCaption();
        $completeReason = $this->completeDisabledReason();
        $canComplete = $this->canCompleteManually();
        $isCompleted = $this->isCompleted();
    @endphp

    <div
        class="tp-floor-receive"
        x-data="tpFloorReceive(@js(['libraryUrl' => asset('vendor/html5-qrcode/html5-qrcode.min.js')]))"
        x-on:destroy="stopCamera()"
        @keydown.escape.window="if (cameraOn) { stopCamera() } else if (cartOpen) { closeCart() }"
    >
        <header class="tp-floor-receive__sticky-header">
            <div class="flex min-w-0 flex-col gap-1">
                <span class="badge badge-outline tp-floor-receive__mode-chip">{{ $this->edgeModeChipLabel() }}</span>
                @if ($this->isScanFirst() && $this->attachedInvoiceFilename())
                    <span class="text-xs opacity-70">Invoice: {{ $this->attachedInvoiceFilename() }}</span>
                @endif
            <div
                class="tp-floor-receive__progress-stats stats stats-horizontal bg-base-200 shadow"
                aria-label="{{ $this->progressChipAriaLabel() }}"
                aria-live="polite"
            >
                <div class="stat">
                    <div class="stat-title">{{ $parentTypeLabel }}</div>
                    <div class="stat-value text-2xl">{{ $this->parentProgressQuantity() }}</div>
                </div>
                @if ($showUnits)
                    <div class="stat">
                        <div class="stat-title">{{ $childTypeLabel }}</div>
                        <div class="stat-value text-2xl">{{ $this->childProgressQuantity() }}</div>
                    </div>
                @endif
            </div>
                @if ($lockedTote = $this->openToteLockedParentLabel())
                    <div class="text-sm font-medium">
                        Open tote {{ $lockedTote }}
                        @if ($lockedProgress = $this->openToteLockedChildProgress())
                            <span class="opacity-70"> · {{ $lockedProgress }} {{ $childTypeLabel }}</span>
                        @endif
                    </div>
                @endif
            </div>

            <div
                class="tp-floor-receive__menu"
                x-data="{ open: false }"
                @keydown.escape.window="open = false"
            >
                <button
                    type="button"
                    class="tp-floor-receive__menu-btn"
                    aria-label="Receive menu"
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
                        href="{{ $desktopUrl }}"
                        class="tp-floor-receive__menu-item"
                        role="menuitem"
                        onclick="document.cookie='{{ $cookieName }}=desktop;path=/;max-age=31536000;SameSite=Lax'"
                    >Open desktop receive</a>

                    @if ($this->canAttachInvoice())
                        <button
                            type="button"
                            class="tp-floor-receive__menu-item"
                            role="menuitem"
                            wire:click="mountAction('attachInvoice')"
                            @click="open = false"
                        >Attach invoice</button>
                    @endif

                    @if ($this->canResetScans())
                        <button
                            type="button"
                            class="tp-floor-receive__menu-item tp-floor-receive__menu-item--danger"
                            role="menuitem"
                            wire:click="mountAction('resetScans')"
                            @click="open = false"
                        >Clear / reset scans</button>
                    @endif

                    @if ($issuesUrl = $this->receivingIssuesUrl())
                        <a href="{{ $issuesUrl }}" class="tp-floor-receive__menu-item" role="menuitem">
                            Report issues
                        </a>
                    @endif
                </div>
            </div>
        </header>

        @if ($isCompleted)
            <div class="tp-floor-receive__complete">
                <div class="tp-floor-receive__complete-title">{{ $this->promptCopy()['completeTitle'] }}</div>
                <p class="tp-floor-receive__complete-body">{{ $this->promptCopy()['completeBody'] }}</p>
                @if ($issuesUrl = $this->receivingIssuesUrl())
                    <p class="tp-floor-receive__complete-link">
                        <a href="{{ $issuesUrl }}">Report receiving issues</a>
                    </p>
                @endif
                <a href="{{ $this->receiveListUrl() }}" class="tp-floor-receive__cancel-btn tp-floor-receive__complete-exit">
                    Back to receives
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
                            placeholder="{{ $this->promptCopy()['scanHelper'] }}"
                            aria-label="{{ $this->promptCopy()['scanHelper'] }}"
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

                @if ($this->highlightUnexpected)
                    <div role="alert" class="tp-floor-receive__status tp-floor-receive__status--error">
                        <span class="tp-floor-receive__status-prefix" aria-hidden="true">Error</span>
                        <span class="tp-floor-receive__status-title">{{ $this->promptCopy()['unexpectedTitle'] }}</span>
                        <span>{{ $this->promptCopy()['unexpectedBody'] }}</span>
                    </div>
                @elseif ($this->lastScanMessage)
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

                <x-staged-scan-panel :staged-scans="$this->stagedScans" />

                @if ($this->canAttachInvoice())
                    <button
                        type="button"
                        class="tp-scanner-macro-btn tp-scanner-macro-btn--neutral min-h-14"
                        wire:click="mountAction('attachInvoice')"
                    >
                        Attach invoice
                    </button>
                @endif

                @if ($this->canCloseOpenTote())
                    <button
                        type="button"
                        class="tp-scanner-macro-btn tp-scanner-macro-btn--neutral min-h-14"
                        wire:click="mountAction('closeOpenTote')"
                        wire:loading.attr="disabled"
                    >
                        Close tote
                    </button>
                @endif

                @if ($this->canAcceptRemaining())
                    <button
                        type="button"
                        class="tp-scanner-macro-btn tp-scanner-macro-btn--neutral min-h-14"
                        @if ($this->acceptRemainingEnabled())
                            wire:click="mountAction('acceptRemaining')"
                        @else
                            disabled
                            aria-disabled="true"
                        @endif
                        wire:loading.attr="disabled"
                    >
                        Accept remaining
                    </button>
                @endif

                @if ($canComplete)
                    <button
                        type="button"
                        class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--ready tp-floor-receive__stage-complete"
                        wire:click="mountAction('completeReceiving')"
                        wire:loading.attr="disabled"
                    >
                        Complete Receive
                    </button>
                @endif
            </div>
        @endif

        @if (! $isCompleted)
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

        {{-- Fullscreen camera overlay --}}
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

        @if (! $isCompleted)
            {{-- Cart sheet --}}
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
                        <span>{{ $parentTypeLabel }}</span>
                        <strong class="tp-scan-qty">{{ $this->parentProgressQuantity() }}</strong>
                    </div>
                    @if ($showUnits)
                        <div class="tp-floor-receive__sheet-progress-row">
                            <span>{{ $childTypeLabel }}</span>
                            <strong class="tp-scan-qty">{{ $this->childProgressQuantity() }}</strong>
                        </div>
                    @endif
                </div>

                <section class="tp-floor-receive__recent" aria-label="Recent scans">
                    @if ($recentCaption)
                        <p class="tp-floor-receive__recent-caption">{{ $recentCaption }}</p>
                    @endif

                    @forelse ($recentLines as $line)
                        <div @class([
                            'tp-floor-receive__recent-row',
                            'tp-floor-receive__recent-row--unexpected' => $line->status === 'unexpected',
                        ])>
                            <span class="tp-floor-receive__recent-id font-mono">{{ $this->recentScanLineLabel($line) }}</span>
                            <span class="tp-floor-receive__recent-meta">
                                {{ ucfirst((string) $line->line_role) }}
                                ·
                                {{ ucfirst((string) $line->status) }}
                            </span>
                        </div>
                    @empty
                        <p class="tp-floor-receive__recent-empty">Scanned items will appear here</p>
                    @endforelse
                </section>

                @if ($this->canShowUnpackOnComplete())
                    <label class="tp-floor-receive__hierarchy">
                        <input
                            type="checkbox"
                            class="tp-floor-receive__hierarchy-check"
                            wire:model.live="unpackOnComplete"
                        />
                        <span>
                            <span class="tp-floor-receive__hierarchy-title">Open cases after receive</span>
                            <span class="tp-floor-receive__hierarchy-help">On by default — uncheck to keep cases sealed.</span>
                        </span>
                    </label>
                @endif

                <div class="tp-floor-receive__sheet-actions">
                    <button
                        type="button"
                        @class([
                            'tp-floor-receive__complete-btn',
                            'tp-floor-receive__complete-btn--ready' => $canComplete,
                            'tp-floor-receive__complete-btn--disabled' => ! $canComplete,
                        ])
                        @if ($canComplete)
                            wire:click="mountAction('completeReceiving')"
                        @else
                            disabled
                            aria-disabled="true"
                            aria-describedby="tp-floor-complete-reason"
                        @endif
                        wire:loading.attr="disabled"
                    >
                        Complete Receive
                    </button>

                    @if ($completeReason)
                        <p id="tp-floor-complete-reason" class="tp-floor-receive__complete-reason">
                            {{ $completeReason }}
                        </p>
                    @endif

                    @if ($this->canUnpackHierarchy())
                        <button
                            type="button"
                            class="tp-floor-receive__cancel-session-btn"
                            wire:click="mountAction('unpackHierarchy')"
                            wire:loading.attr="disabled"
                        >
                            Unpack hierarchy
                        </button>
                    @endif

                    @if ($this->canCancelReceiving())
                        <button
                            type="button"
                            class="tp-floor-receive__cancel-session-btn"
                            wire:click="mountAction('cancelReceiving')"
                            wire:loading.attr="disabled"
                        >
                            Cancel receive
                        </button>
                    @endif

                    @if ($this->canHardDeleteReceiving())
                        <button
                            type="button"
                            class="tp-floor-receive__cancel-session-btn"
                            wire:click="mountAction('deleteReceiving')"
                            wire:loading.attr="disabled"
                        >
                            Delete receive
                        </button>
                    @endif

                    <a href="{{ $this->receiveListUrl() }}" class="tp-floor-receive__cancel-btn">
                        Back to receives
                    </a>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
