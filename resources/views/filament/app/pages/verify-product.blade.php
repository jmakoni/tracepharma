<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($this->showScorecard() && ($scorecard = $this->scorecardMetrics()))
            <div class="stats stats-vertical w-full shadow-xl sm:stats-horizontal bg-base-100">
                <div class="stat">
                    <div class="stat-title">Allowed (24h)</div>
                    <div class="stat-value text-success">{{ number_format($scorecard['allowed']) }}</div>
                    <div class="stat-desc">Verified dispense checks</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Blocked (24h)</div>
                    <div class="stat-value text-error">{{ number_format($scorecard['blocked']) }}</div>
                    <div class="stat-desc">Failed or suspect verifications</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Deferred (24h)</div>
                    <div class="stat-value text-warning">{{ number_format($scorecard['deferred']) }}</div>
                    <div class="stat-desc">Retry later</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Unavailable (24h)</div>
                    <div class="stat-value text-warning">{{ number_format($scorecard['unavailable']) }}</div>
                    <div class="stat-desc">VRS unreachable</div>
                </div>
            </div>
        @endif

        @if ($historyUrl = $this->verificationHistoryUrl())
            <div class="text-sm">
                <a href="{{ $historyUrl }}" class="link link-primary">View verification history (today)</a>
            </div>
        @endif

        <div class="alert alert-info">
            <div class="flex flex-col gap-1 text-sm">
                <span class="font-semibold">PMS dispense bridge</span>
                <span>
                    POST <code class="font-mono text-xs">{{ url('/api/v1/dispense-check') }}</code>
                    with a Sanctum token — returns <code class="font-mono text-xs">allowed</code> / status for pharmacy management systems.
                </span>
            </div>
        </div>

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
                @if ($this->lastScanMessage)
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
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3">
                            <div class="flex flex-col gap-1">
                                <span class="font-semibold">{{ $this->lastScanMessage }}</span>
                                @if ($this->lastScanDetail)
                                    <x-copyable-identifier :value="$this->lastScanDetail" title="Copy identifier" class="font-mono text-sm" />
                                @endif
                            </div>
                            @if ($url = $this->exceptionUrl())
                                <a href="{{ $url }}" class="btn btn-sm btn-outline">
                                    Open exception
                                </a>
                            @endif
                        </div>
                    </div>
                @endif

                <form
                    wire:submit.prevent="verifyScan"
                    x-data
                    x-init="$nextTick(() => $refs.scanInput?.focus())"
                    x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                    class="flex flex-col gap-4"
                >
                    <div class="form-control w-full gap-1.5">
                        <label for="verify-scan-input" class="label-text text-sm font-medium">
                            Scan product label
                        </label>
                        <div class="flex w-full items-stretch gap-2">
                            <input
                                id="verify-scan-input"
                                type="text"
                                wire:model="scan"
                                x-ref="scanInput"
                                autocomplete="off"
                                class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                placeholder="Scan GTIN + serial (2D barcode)"
                            />
                            <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                Verify
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if ($verifications = $this->todaysVerifications())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-3">
                    <h2 class="card-title text-base">Today's verifications</h2>
                    <ul class="divide-y divide-base-200">
                        @foreach ($verifications as $verification)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <div class="font-mono inline-flex flex-wrap items-center gap-1">
                                    @php
                                        $verifyScan = filled($verification->scanned_barcode)
                                            ? (string) $verification->scanned_barcode
                                            : ((filled($verification->gtin14) && filled($verification->serial))
                                                ? '(01)'.$verification->gtin14.'(21)'.$verification->serial
                                                : null);
                                    @endphp
                                    <x-copyable-identifier :value="$verification->gtin14" title="Copy GTIN">
                                        @if ($verifyScan)
                                            <a
                                                href="{{ \App\Filament\App\Pages\AssetTracking::getUrl(['scan' => $verifyScan]) }}"
                                                class="tp-trace-link"
                                            >{{ $verification->gtin14 }}</a>
                                        @else
                                            <span>{{ $verification->gtin14 }}</span>
                                        @endif
                                    </x-copyable-identifier>
                                    <span>·</span>
                                    <x-copyable-identifier :value="$verification->serial" title="Copy serial" />
                                    @if ($verification->lot)
                                        <span>·</span>
                                        <x-copyable-identifier :value="$verification->lot" title="Copy lot" />
                                    @endif
                                </div>
                                <span @class(['badge badge-outline', $this->statusBadgeClass($verification->status)])>
                                    {{ $this->statusLabel($verification->status) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
