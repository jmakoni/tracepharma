<x-filament-panels::page>
    @assets
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}" data-tp-html5-qrcode="1"></script>
        <script src="{{ asset('js/tp-floor-receive.js') }}?v={{ @filemtime(public_path('js/tp-floor-receive.js')) ?: time() }}"></script>
    @endassets

    <x-scan-flash />

    {{-- Auto layout redirect only (visible desktop/floor links hidden via CSS). --}}
    <div class="tp-floor-receive__layout-switch">
        @include('filament.app.partials.receive-layout-switch', [
            'mode' => 'floor',
            'desktopUrl' => $this->desktopReceiveUrl(),
            'floorUrl' => $this->floorReceiveUrl(),
        ])
    </div>

    @php
        $childTypeLabel = $this->childTypeLabel();
        $canComplete = $this->canCompleteManually();
        $isCompleted = $this->isCompleted();
        $showAccept = $this->canAcceptRemaining() && $this->acceptRemainingEnabled();
        $scanPlaceholder = $this->floorScanPlaceholder();
    @endphp

    <div
        class="tp-floor-receive"
        x-data="tpFloorReceive(@js(\App\Support\Floor\FloorCameraScanAlpine::tpFloorReceiveConfig()))"
        x-on:destroy="stopCamera()"
        @keydown.escape.window="if (cameraOn) { stopCamera() }"
        x-on:focus-scan.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
        x-on:close-modal.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
        x-on:modal-closed.window="if (!cameraOn) { $nextTick(() => $refs.scanInput?.focus()) }"
    >
        <header class="tp-floor-receive__sticky-header">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="tp-floor-receive__header-top">
                    @include('filament.app.partials.floor-task-menu')
                    <span class="badge badge-outline tp-floor-receive__mode-chip">{{ $this->edgeModeChipLabel() }}</span>
                </div>

                @if ($this->chipDeaLabel)
                    <span @class([
                        'badge badge-outline tp-floor-receive__mode-chip',
                        'badge-error' => $this->chipDeaColor === 'danger',
                        'badge-warning' => $this->chipDeaColor === 'warning',
                    ])>{{ $this->chipDeaLabel }}</span>
                @endif
                @if ($this->isScanFirst() && $this->attachedInvoiceFilename())
                    <span class="text-xs opacity-70">Invoice: {{ $this->attachedInvoiceFilename() }}</span>
                @endif

                {{-- Floor: skip order rollup chips (PO/ASN/Parents/Eaches) — session progress below is enough; desktop keeps the header. --}}
                @include('filament.app.partials.receiving-session-progress-stats', [
                    'progress' => $this->sessionProgress(),
                    'class' => 'tp-floor-receive__progress-stats stats stats-horizontal bg-base-200 shadow',
                ])

                @if ($lockedTote = $this->openToteLockedParentLabel())
                    <div class="text-sm font-medium">
                        Open tote {{ $lockedTote }}
                        @if ($lockedProgress = $this->openToteLockedChildProgress())
                            <span class="opacity-70"> · {{ $lockedProgress }} {{ $childTypeLabel }}</span>
                        @endif
                    </div>
                @endif
            </div>
        </header>

        @if ($isCompleted)
            @php
                $documentComplete = $this->documentReceiveComplete();
            @endphp
            <div class="tp-floor-receive__complete">
                <div class="tp-floor-receive__complete-title">{{ $this->promptCopy()['completeTitle'] }}</div>
                <p class="tp-floor-receive__complete-body">{{ $this->promptCopy()['completeBody'] }}</p>
                @if ($issuesUrl = $this->receivingIssuesUrl())
                    <p class="tp-floor-receive__complete-link">
                        <a href="{{ $issuesUrl }}">Report receiving issues</a>
                    </p>
                @endif
                @if ($documentComplete)
                    <a href="{{ $this->receiveListUrl() }}" class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--ready tp-floor-receive__complete-exit">
                        Back to receives
                    </a>
                @else
                    <button
                        type="button"
                        class="tp-floor-receive__complete-btn tp-floor-receive__complete-btn--ready tp-floor-receive__complete-exit"
                        wire:click="startNextReceive"
                        wire:loading.attr="disabled"
                    >
                        Start next receive
                    </button>
                    <a href="{{ $this->receiveListUrl() }}" class="tp-floor-receive__cancel-btn">
                        Back to receives
                    </a>
                @endif
            </div>
        @else
            <div class="tp-floor-receive__stage">
                <form
                    wire:submit.prevent="confirmScanInput"
                    x-init="$nextTick(() => $refs.scanInput?.focus())"
                    class="tp-floor-receive__scan-form"
                >
                    <p
                        x-show="connectionError"
                        x-cloak
                        x-text="connectionError"
                        class="tp-floor-receive__camera-error mb-2 w-full"
                        role="alert"
                    ></p>
                    <div class="tp-floor-receive__scan-field">
                        <input
                            id="floor-scan-input"
                            type="text"
                            inputmode="none"
                            wire:model.live.blur="scan"
                            x-ref="scanInput"
                            x-on:keydown.enter.prevent="$wire.confirmScanInput($refs.scanInput.value)"
                            autocomplete="off"
                            autofocus
                            class="tp-floor-receive__scan-input"
                            placeholder="{{ $scanPlaceholder }}"
                            aria-label="{{ $scanPlaceholder }}"
                            wire:loading.attr="disabled"
                        />
                    </div>

                    <button
                        type="button"
                        class="tp-floor-receive__camera-btn"
                        x-ref="cameraBtn"
                        :aria-label="cameraOn ? 'Close camera' : 'Open camera scanner'"
                        :aria-pressed="cameraOn ? 'true' : 'false'"
                        x-bind:disabled="starting"
                        wire:loading.attr="disabled"
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

                @php($outstanding = $this->outstandingReceive())
                @if ($outstanding['heading'] !== '')
                    <x-confirmed-scan-panel
                        :heading="$outstanding['heading']"
                        :rows="$outstanding['rows']"
                        :caption="$outstanding['caption']"
                        empty="Nothing left to scan."
                    />
                @endif

                <x-confirmed-scan-panel
                    :rows="$this->recentConfirmedScanRows()"
                    :caption="$this->recentScansCaption()"
                />

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

                @unless ($canComplete)
                    @if ($completeReason = $this->completeDisabledReason())
                        <p class="tp-floor-receive__complete-reason">{{ $completeReason }}</p>
                    @endif
                @endunless
            </div>

            <div class="tp-floor-receive__footer" role="group" aria-label="Receive actions">
                @if ($canComplete)
                    <button
                        type="button"
                        class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--confirm"
                        wire:click="mountAction('completeReceiving')"
                        wire:loading.attr="disabled"
                    >
                        Complete session
                    </button>
                @endif

                @if ($showAccept)
                    <button
                        type="button"
                        class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--neutral"
                        wire:click="mountAction('acceptRemaining')"
                        wire:loading.attr="disabled"
                    >
                        Accept remaining
                    </button>
                @endif

                @if ($this->canCloseTransferWithShortage())
                    <button
                        type="button"
                        class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--warning min-h-14"
                        wire:click="mountAction('closeTransferWithShortage')"
                        wire:loading.attr="disabled"
                    >
                        Close with shortage
                    </button>
                @endif

                @if ($this->canHardDeleteReceiving())
                    <button
                        type="button"
                        class="tp-floor-receive__footer-btn tp-floor-receive__footer-btn--cancel"
                        wire:click="mountAction('deleteReceiving')"
                        wire:loading.attr="disabled"
                    >
                        Cancel
                    </button>
                @endif
            </div>
        @endif

        @include('filament.app.partials.floor-camera-overlay', [
            'stats' => array_values(array_filter([
                [
                    'title' => $this->parentTypeLabel(),
                    'value' => $this->parentProgressQuantity(),
                ],
                $this->showUnitsProgress()
                    ? [
                        'title' => $this->childTypeLabel(),
                        'value' => $this->childProgressQuantity(),
                    ]
                    : null,
            ])),
            'decode' => in_array($this->lastScanTone, ['ok', 'warn'], true) ? $this->lastScanDetail : null,
            'error' => $this->lastScanTone === 'error' ? $this->lastScanMessage : null,
        ])
    </div>
</x-filament-panels::page>
