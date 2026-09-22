<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @include('filament.app.partials.receive-layout-switch', [
            'mode' => 'desktop',
            'desktopUrl' => $this->desktopReceiveUrl(),
            'floorUrl' => $this->floorReceiveUrl(),
        ])

        <div
            x-data="{ flashTone: null }"
            x-on:scan-result.window="
                flashTone = $event.detail.tone;
                setTimeout(() => { flashTone = null }, 700)
            "
            :class="{
                'ring-4 ring-success/40': flashTone === 'ok',
                'ring-4 ring-warning/40': flashTone === 'warn',
                'ring-4 ring-error/40': flashTone === 'error',
            }"
            class="card bg-base-100 shadow-xl transition-shadow"
        >
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="badge badge-outline">{{ $this->kindBadgeLabel() }}</span>
                        <span class="badge badge-outline">{{ $this->edgeModeChipLabel() }}</span>
                        @if ($this->isScanFirst())
                            <span>{{ $this->getRecord()->site?->name ?? 'Receive site' }}</span>
                        @elseif ($this->isTransferReceive())
                            <span>Transfer #{{ $this->getRecord()->transferring_session_id }}</span>
                            @if ($this->getRecord()->site?->name)
                                <span aria-hidden="true">·</span>
                                <span>{{ $this->getRecord()->site->name }}</span>
                            @endif
                        @else
                            <span>{{ $this->getRecord()->tradingPartner?->name ?? 'No partner on file' }}</span>
                            @if ($this->getRecord()->site?->name)
                                <span aria-hidden="true">·</span>
                                <span>{{ $this->getRecord()->site->name }}</span>
                            @endif
                        @endif
                    </div>

                    <span @class([
                        'badge badge-lg',
                        'badge-success' => $this->statusBadgeColor() === 'success',
                        'badge-warning' => $this->statusBadgeColor() === 'warning',
                        'badge-outline' => $this->statusBadgeColor() === 'outline',
                    ])>
                        {{ $this->statusLabel() }}
                    </span>
                </div>

                <div class="flex flex-wrap gap-1.5" aria-label="Scan context">
                    @if ($this->chipHasTi === true)
                        <span class="badge badge-success badge-outline">TI OK</span>
                    @elseif ($this->chipHasTi === false)
                        <span class="badge badge-warning badge-outline">TI missing</span>
                    @endif

                    @if ($this->chipMatchedAsnDocumentId)
                        <span class="badge badge-info badge-outline">
                            Matched ASN{{ filled($this->chipMatchedAsnLabel) ? ': '.$this->chipMatchedAsnLabel : ' #'.$this->chipMatchedAsnDocumentId }}
                        </span>
                    @endif

                    @if ($this->chipTransferSessionId)
                        <span class="badge badge-warning badge-outline">Transfer #{{ $this->chipTransferSessionId }}</span>
                    @endif

                    @if ($this->chipDeaLabel)
                        <span @class([
                            'badge badge-outline',
                            'badge-error' => $this->chipDeaColor === 'danger',
                            'badge-warning' => $this->chipDeaColor === 'warning',
                        ])>{{ $this->chipDeaLabel }}</span>
                    @endif

                    @if ($this->isScanFirst() && $this->attachedInvoiceFilename())
                        <span class="badge badge-ghost">Invoice: {{ $this->attachedInvoiceFilename() }}</span>
                    @endif
                </div>

                @include('filament.app.partials.expected-order-header', [
                    'header' => $this->expectedOrderHeader(),
                ])

                @include('filament.app.partials.receiving-session-progress-stats', [
                    'progress' => $this->sessionProgress(),
                ])

                @if ($lockedTote = $this->openToteLockedParentLabel())
                    <div class="rounded-lg border border-base-300 bg-base-200/60 px-3 py-2 text-sm font-medium">
                        Open tote {{ $lockedTote }}
                        @if ($lockedProgress = $this->openToteLockedChildProgress())
                            <span class="opacity-70"> · {{ $lockedProgress }} {{ $this->childTypeLabel() }}</span>
                        @endif
                    </div>
                @endif

                @if ($this->highlightUnexpected)
                    <div role="alert" class="alert alert-error">
                        <div class="flex flex-col gap-1">
                            <span class="font-semibold">{{ $this->promptCopy()['unexpectedTitle'] }}</span>
                            <span class="text-sm">{{ $this->promptCopy()['unexpectedBody'] }}</span>
                        </div>
                    </div>
                @elseif ($this->lastScanMessage)
                    <div
                        role="status"
                        aria-live="{{ $this->lastScanTone === 'error' ? 'assertive' : 'polite' }}"
                        @class([
                            'alert',
                            'alert-success' => $this->lastScanTone === 'ok',
                            'alert-warning' => $this->lastScanTone === 'warn',
                            'alert-error' => $this->lastScanTone === 'error',
                        ])
                    >
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3">
                            <span class="font-semibold">{{ $this->lastScanMessage }}</span>
                            @if ($this->lastScanDetail)
                                <x-copyable-identifier :value="$this->lastScanDetail" title="Copy identifier">
                                    @if ($this->lastScanHref)
                                        <a href="{{ $this->lastScanHref }}" class="tp-trace-link font-mono text-sm">{{ $this->lastScanDetail }}</a>
                                    @else
                                        <span class="font-mono text-sm">{{ $this->lastScanDetail }}</span>
                                    @endif
                                </x-copyable-identifier>
                            @endif
                            @if ($this->lastScanContextLinks !== [])
                                <span class="text-xs opacity-80">
                                    {!! \App\Support\Tracing\EpcContextLinks::renderHtml($this->lastScanContextLinks) !!}
                                </span>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($this->isCompleted())
                    @php
                        $documentComplete = $this->documentReceiveComplete();
                        $epcisPending = $this->transferReceiveEpcisPending();
                    @endphp
                    <div @class([
                        'rounded-lg border p-4',
                        'border-warning/30 bg-warning/10' => $epcisPending,
                        'border-success/30 bg-success/10' => ! $epcisPending,
                    ])>
                        <div class="text-lg font-semibold">{{ $this->promptCopy()['completeTitle'] }}</div>
                        <p class="text-sm">
                            {{ $this->promptCopy()['completeBody'] }}
                        </p>
                        @if ($epcisPending)
                            <div role="alert" class="alert alert-warning mt-3">
                                <div class="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <span>Receiving EPCIS was not authored. Received scans are saved.</span>
                                    @if ($this->canRetryReceiveEpcis())
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-warning min-h-14"
                                            wire:click="mountAction('retryReceiveEpcis')"
                                            wire:loading.attr="disabled"
                                        >
                                            Retry receive EPCIS
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if ($issuesUrl = $this->receivingIssuesUrl())
                            <p class="mt-2 text-sm">
                                <a href="{{ $issuesUrl }}" class="link link-hover font-medium">
                                    Report receiving issues
                                </a>
                                <span class="opacity-70"> — shortage, overage, or damaged after receive.</span>
                            </p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($documentComplete)
                                <a href="{{ $this->receiveListUrl() }}" class="btn btn-primary btn-sm">
                                    Back to receives
                                </a>
                            @else
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    wire:click="startNextReceive"
                                    wire:loading.attr="disabled"
                                >
                                    Start next receive
                                </button>
                                <a href="{{ $this->receiveListUrl() }}" class="btn btn-ghost btn-sm">
                                    Back to receives
                                </a>
                            @endif
                        </div>
                    </div>
                @elseif ($this->isHeld())
                    <div class="rounded-lg border border-warning/30 bg-warning/10 p-4">
                        <div class="text-lg font-semibold">Complete to hold — waiting for EPCIS</div>
                        <p class="text-sm">Confirmed serials are held, not sellable. Complete still requires a file. This session stays for Investigator until inbound EPCIS arrives.</p>
                        <div class="mt-3">
                            <a href="{{ $this->receiveListUrl() }}" class="btn btn-primary btn-sm">
                                Back to receives
                            </a>
                        </div>
                    </div>
                @elseif ($this->isCancelled())
                    <div class="rounded-lg border border-warning/30 bg-warning/10 p-4">
                        <div class="text-lg font-semibold">Receive cancelled</div>
                        <p class="text-sm">This session is closed. Open a new receive to continue.</p>
                        <div class="mt-3">
                            <a href="{{ $this->receiveListUrl() }}" class="btn btn-primary btn-sm">
                                Back to receives
                            </a>
                        </div>
                    </div>
                @else
                    <div class="flex flex-col gap-4">
                        <x-scan-field
                            variant="desktop"
                            :show-camera="false"
                            input-id="scan-input"
                            :label="$this->isTransferReceive() ? 'Scan to receive' : 'Scan barcode'"
                            :placeholder="$this->promptCopy()['scanHelper']"
                            :confirm-label="$this->promptCopy()['confirmButton']"
                            submit-action="confirmScan"
                            submit-method="confirmScanInput"
                        />

                        @if ($this->canAttachInvoice())
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <button
                                    type="button"
                                    class="tp-scanner-macro-btn tp-scanner-macro-btn--neutral btn btn-outline min-h-14"
                                    wire:click="mountAction('attachInvoice')"
                                >
                                    Attach invoice
                                </button>
                                @if ($this->attachedInvoiceFilename())
                                    <span class="text-sm opacity-70">{{ $this->attachedInvoiceFilename() }}</span>
                                @endif
                            </div>
                        @endif

                        @if ($this->canCloseOpenTote() || $this->canAcceptRemaining() || $this->canCompleteManually() || $this->canCompleteToHold() || $this->canCloseTransferWithShortage())
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                @if ($this->canCompleteManually())
                                    <button
                                        type="button"
                                        class="tp-scanner-macro-btn btn btn-primary min-h-14"
                                        wire:click="mountAction('completeReceiving')"
                                        wire:loading.attr="disabled"
                                    >
                                        Complete session
                                    </button>
                                @endif
                                @if ($this->canCompleteToHold())
                                    <button
                                        type="button"
                                        class="tp-scanner-macro-btn btn btn-warning min-h-14"
                                        wire:click="mountAction('completeToHold')"
                                        wire:loading.attr="disabled"
                                    >
                                        Complete to hold — waiting for EPCIS
                                    </button>
                                @endif
                                @if ($this->canCloseOpenTote())
                                    <button
                                        type="button"
                                        class="tp-scanner-macro-btn tp-scanner-macro-btn--neutral btn btn-outline min-h-14"
                                        wire:click="mountAction('closeOpenTote')"
                                        wire:loading.attr="disabled"
                                    >
                                        Close tote
                                    </button>
                                @endif
                                @if ($this->canAcceptRemaining())
                                    <button
                                        type="button"
                                        class="tp-scanner-macro-btn tp-scanner-macro-btn--neutral btn btn-outline min-h-14"
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
                                @if ($this->canCloseTransferWithShortage())
                                    <button
                                        type="button"
                                        class="tp-scanner-macro-btn btn btn-warning min-h-14"
                                        wire:click="mountAction('closeTransferWithShortage')"
                                        wire:loading.attr="disabled"
                                    >
                                        Close with shortage
                                    </button>
                                @endif
                            </div>
                        @endif

                        @unless ($this->canCompleteManually())
                            @if ($reason = $this->completeDisabledReason())
                                <p class="text-sm opacity-70">{{ $reason }}</p>
                            @endif
                        @endunless

                        @if ($this->canShowUnpackOnComplete())
                            <label class="label cursor-pointer justify-start gap-3 min-h-14 rounded-lg border border-base-300 bg-base-200/60 px-3">
                                <input
                                    type="checkbox"
                                    class="checkbox checkbox-lg"
                                    wire:model.live="unpackOnComplete"
                                />
                                <span class="label-text text-sm">
                                    <span class="font-medium">Unpack after receive (break hierarchy)</span>
                                    <span class="block opacity-70">On by default — uncheck to keep parent/child links sealed.</span>
                                </span>
                            </label>
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
                    </div>
                @endif
            </div>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
