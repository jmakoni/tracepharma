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
            class="card bg-base-100 shadow-xl transition-shadow sticky top-0 z-10"
        >
            <div class="card-body gap-4">
                @if ($this->lastMessage)
                    <div
                        role="status"
                        aria-live="{{ $this->lastTone === 'error' ? 'assertive' : 'polite' }}"
                        @class([
                            'alert',
                            'alert-success' => $this->lastTone === 'ok',
                            'alert-warning' => $this->lastTone === 'warn',
                            'alert-error' => $this->lastTone === 'error',
                        ])
                    >
                        <span class="font-semibold">{{ $this->lastMessage }}</span>
                    </div>
                @endif

                <form
                    wire:submit.prevent="processScan"
                    x-data
                    x-init="$nextTick(() => $refs.scanInput?.focus())"
                    x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                    class="flex flex-col gap-4"
                >
                    <div class="form-control w-full gap-1.5">
                        <label for="commission-all-scan-input" class="label-text text-sm font-medium">
                            Scan EPC to commission
                        </label>
                        <div class="flex w-full items-stretch gap-2">
                            <input
                                id="commission-all-scan-input"
                                type="text"
                                wire:model="scan"
                                x-ref="scanInput"
                                autocomplete="off"
                                class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                placeholder="Scan SSCC or SGTIN"
                            />
                            <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                Add
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="card-title text-base">Confirmed ({{ count($this->confirmed) }})</h2>
                    @if ($this->confirmed !== [])
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="clearConfirmed">
                            Clear list
                        </button>
                    @endif
                </div>

                @forelse ($this->confirmed as $row)
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-base-300 px-3 py-2">
                        <span class="font-mono text-sm">{{ $row['label'] }}</span>
                        <button
                            type="button"
                            class="btn btn-ghost btn-xs"
                            wire:click="removeConfirmed({{ (int) $row['epc_id'] }})"
                        >
                            Remove
                        </button>
                    </div>
                @empty
                    <p class="text-sm opacity-70">Scan on-hand EPCs that still need a commissioning ObjectEvent.</p>
                @endforelse

                <button
                    type="button"
                    class="btn btn-primary min-h-14"
                    wire:click="mountAction('confirmCommission')"
                    wire:loading.attr="disabled"
                    @disabled($this->confirmed === [])
                >
                    Complete commission-all
                </button>
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
