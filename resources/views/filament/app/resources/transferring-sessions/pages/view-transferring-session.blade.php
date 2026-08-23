<x-filament-panels::page>
    <div class="flex flex-col gap-4">
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
                        <span class="font-medium">{{ $this->getRecord()->fromSite?->name ?? 'From site' }}</span>
                        <span aria-hidden="true">→</span>
                        <span class="font-medium">{{ $this->getRecord()->toSite?->name ?? 'To site' }}</span>
                    </div>

                    <span @class([
                        'badge badge-lg',
                        'badge-success' => $this->statusBadgeColor() === 'success',
                        'badge-info' => $this->statusBadgeColor() === 'info',
                        'badge-warning' => $this->statusBadgeColor() === 'warning',
                        'badge-outline' => $this->statusBadgeColor() === 'outline',
                    ])>
                        {{ $this->statusLabel() }}
                    </span>
                </div>

                @if (! $this->isInTransit())
                    <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow" aria-live="polite">
                        <div class="stat">
                            <div class="stat-title">Confirmed</div>
                            <div class="stat-value text-2xl">
                                {{ (int) $this->getRecord()->confirmed_count }}
                            </div>
                        </div>
                        @if ($this->isCompleted())
                            <div class="stat">
                                <div class="stat-title">Received</div>
                                <div class="stat-value text-2xl">
                                    {{ (int) $this->getRecord()->received_count }}
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($this->lastScanMessage && ! $this->isInTransit())
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
                        <div class="text-lg font-semibold">Transfer complete</div>
                        <p class="text-sm">
                            Destination receive scans verified. Shipping and receiving EPCIS events are on the transfer document.
                        </p>
                        @if ($this->getRecord()->transferDocument)
                            <div class="mt-3">
                                <a
                                    href="{{ $this->getRecord()->transferDocument->filamentViewUrl() }}"
                                    class="link link-primary font-medium"
                                >
                                    View transfer EPCIS document
                                </a>
                            </div>
                        @endif
                    </div>
                @elseif ($this->isInTransit())
                    <div class="rounded-lg border border-info/30 bg-info/10 p-4">
                        <div class="text-lg font-semibold">Transfer shipped</div>
                        <p class="text-sm">
                            Shipping EPCIS event authored. Receive this transfer at the destination to complete it.
                        </p>
                        <div class="mt-3">
                            @if (\App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource::canAccess())
                                @if ($this->receivingSession())
                                    <a
                                        href="{{ \App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource::getUrl('view', ['record' => $this->receivingSession()], panel: 'app') }}"
                                        class="btn btn-primary btn-sm"
                                    >
                                        Open receive session #{{ $this->receivingSession()->getKey() }}
                                    </a>
                                @else
                                    <button
                                        type="button"
                                        wire:click="mountAction('receiveAtDestination')"
                                        class="btn btn-primary btn-sm"
                                    >
                                        Receive at destination
                                    </button>
                                @endif
                            @endif
                        </div>
                    </div>
                @else
                    <form
                        wire:submit.prevent="stageScan"
                        x-data
                        x-init="$nextTick(() => $refs.scanInput?.focus())"
                        x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                        class="flex flex-col gap-4"
                    >
                        <div class="form-control w-full gap-1.5">
                            <label for="scan-input" class="label-text text-sm font-medium">
                                Scan barcode
                            </label>
                            <div class="flex w-full items-stretch gap-2">
                                <input
                                    id="scan-input"
                                    type="text"
                                    wire:model.live.blur="scan"
                                    x-ref="scanInput"
                                    x-on:keydown.enter.prevent="$wire.stageScan($refs.scanInput.value)"
                                    autocomplete="off"
                                    class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                    placeholder="Scan SSCC or SGTIN"
                                />
                                <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                    ADD
                                </button>
                            </div>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        {{ $this->content }}
    </div>
</x-filament-panels::page>
