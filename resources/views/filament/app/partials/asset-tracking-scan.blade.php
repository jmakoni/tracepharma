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
        @if ($this->trace && ! $this->trace['found'])
            <div role="alert" aria-live="assertive" class="alert alert-error">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="font-semibold">No asset found for this scan.</span>
                        <span class="font-mono text-sm">{{ $this->trace['scan'] }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($url = $this->findRecallUrl())
                            <a href="{{ $url }}" class="btn btn-sm btn-outline">Find / Recall</a>
                        @endif
                        @if ($url = $this->verifyProductUrl())
                            <a href="{{ $url }}" class="btn btn-sm btn-outline">Verify product</a>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        <form
            wire:submit.prevent="runTrace"
            x-data
            x-init="$nextTick(() => $refs.scanInput?.focus())"
            x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
            class="flex flex-col gap-4"
        >
            <div class="form-control w-full gap-1.5">
                <label for="asset-tracking-scan-input" class="label-text text-sm font-medium">
                    Scan SGTIN or SSCC
                </label>
                <div class="flex w-full items-stretch gap-2">
                    <input
                        id="asset-tracking-scan-input"
                        type="text"
                        wire:model="scan"
                        x-ref="scanInput"
                        autocomplete="off"
                        class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                        placeholder="Scan GTIN+serial (SGTIN) or SSCC"
                    />
                    <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                        Trace
                    </button>
                </div>
            </div>
        </form>

        @if ($this->trace && $this->trace['found'])
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="badge badge-outline">Traced {{ $this->trace['primary_identifier'] }}</span>
                @if (! empty($this->trace['as_of']))
                    <span class="badge badge-ghost">As of {{ $this->trace['as_of'] }}</span>
                @endif
                @if ($url = $this->findRecallUrl())
                    <a href="{{ $url }}" class="link link-hover">Find / Recall</a>
                @endif
            </div>
        @endif
    </div>
</div>
