@php
    $scanInputId = $scanInputId ?? 'scan-input';
    $showAddFromReceived = $showAddFromReceived ?? false;
    $useScanFieldComponent = $useScanFieldComponent ?? false;
@endphp

@if ($this->wizardShowsCustomerNudge())
    <div class="alert alert-info" role="status">
        <span class="text-sm">
            Scans confirmed — set the customer and ship-to on step 2 before sending.
        </span>
        <button type="button" wire:click="goToStep(2)" class="btn btn-sm btn-outline">
            Go to Customer
        </button>
    </div>
@endif

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
        <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3">
            <span class="font-semibold">{{ $this->lastScanMessage }}</span>
            @if (property_exists($this, 'lastScanDetail') && $this->lastScanDetail)
                <x-copyable-identifier :value="$this->lastScanDetail" title="Copy identifier">
                    @if ($this->lastScanHref)
                        <a href="{{ $this->lastScanHref }}" class="tp-trace-link font-mono text-sm">{{ $this->lastScanDetail }}</a>
                    @else
                        <span class="font-mono text-sm">{{ $this->lastScanDetail }}</span>
                    @endif
                </x-copyable-identifier>
            @endif
            @if (property_exists($this, 'lastScanContextLinks') && $this->lastScanContextLinks !== [])
                <span class="text-xs opacity-80">
                    {!! \App\Support\Tracing\EpcContextLinks::renderHtml($this->lastScanContextLinks) !!}
                </span>
            @endif
        </div>
    </div>
@endif

@if ($this->canScan())
    @if ($useScanFieldComponent)
        <x-scan-field
            variant="desktop"
            :show-camera="false"
            :input-id="$scanInputId"
            label="Scan barcode"
            placeholder="Scan SSCC or SGTIN"
            confirm-label="ADD"
            submit-action="confirmScan"
        />
    @else
        <form
            wire:submit.prevent="stageScan"
            x-data
            x-init="$nextTick(() => $refs.scanInput?.focus())"
            x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full gap-1.5">
                <label for="{{ $scanInputId }}" class="label-text text-sm font-medium">
                    Scan barcode
                </label>
                <div class="flex w-full items-stretch gap-2">
                    <input
                        id="{{ $scanInputId }}"
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

    <div class="flex flex-wrap gap-2">
        @if ($showAddFromReceived && method_exists($this, 'addFromReceivedAction'))
            {{ ($this->addFromReceivedAction)([]) }}
        @endif
        <button type="button" wire:click="goToStep(2)" class="btn btn-outline btn-sm">
            Next: Customer →
        </button>
    </div>
@endif
