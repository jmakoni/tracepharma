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

                <p class="text-sm opacity-70">{{ $this->promptCopy()['kindHelper'] }}</p>

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
                </div>

                <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow" aria-live="polite">
                    <div class="stat">
                        <div class="stat-title">{{ $this->parentTypeLabel() }}</div>
                        <div class="stat-value text-2xl">
                            {{ $this->parentProgressQuantity() }}
                        </div>
                    </div>
                    @if ($this->showUnitsProgress())
                        <div class="stat">
                            <div class="stat-title">{{ $this->childTypeLabel() }}</div>
                            <div class="stat-value text-2xl">
                                {{ $this->childProgressQuantity() }}
                            </div>
                        </div>
                    @endif
                </div>

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
                    <div class="rounded-lg border border-success/30 bg-success/10 p-4">
                        <div class="text-lg font-semibold">{{ $this->promptCopy()['completeTitle'] }}</div>
                        <p class="text-sm">
                            {{ $this->promptCopy()['completeBody'] }}
                        </p>
                        @if ($issuesUrl = $this->receivingIssuesUrl())
                            <p class="mt-2 text-sm">
                                <a href="{{ $issuesUrl }}" class="link link-hover font-medium">
                                    Report receiving issues
                                </a>
                                <span class="opacity-70"> — shortage, overage, or damaged after receive.</span>
                            </p>
                        @endif
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
                        />

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
                    </div>
                @endif
            </div>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
