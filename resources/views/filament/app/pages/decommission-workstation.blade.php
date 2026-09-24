<x-filament-panels::page>
    <x-scanner-desk>
        <x-slot:header>
            <x-scanner-desk-header>
                <x-slot:context>
                    <span class="badge badge-lg badge-outline font-semibold">
                        Site: {{ $this->contextSiteLabel() }}
                    </span>
                </x-slot:context>

                <x-slot:qty>
                    @if ($this->confirmed !== [])
                        @include('filament.app.partials.scanner-progress-stats', [
                            'stats' => [
                                ['title' => 'Confirmed', 'value' => count($this->confirmed)],
                            ],
                            'class' => 'stats stats-horizontal bg-base-200 shadow',
                        ])
                    @endif
                </x-slot:qty>

                <x-slot:alert>
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
                </x-slot:alert>

                <x-slot:scan>
                    <form
                        x-on:submit.prevent="$wire.processScan($refs.scanInput.value)"
                        x-data
                        x-init="$nextTick(() => $refs.scanInput?.focus())"
                        x-on:focus-scan.window="$nextTick(() => $refs.scanInput?.focus())"
                        x-on:keydown.enter.prevent="$wire.processScan($refs.scanInput.value)"
                        class="flex flex-col gap-3"
                    >
                        <div class="flex w-full flex-col gap-3">
                            <label for="decommission-scan-input" class="text-sm font-medium">
                                Scan EPC to decommission
                            </label>
                            <div class="flex w-full items-stretch gap-2">
                                <input
                                    id="decommission-scan-input"
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
                </x-slot:scan>
            </x-scanner-desk-header>
        </x-slot:header>

        <x-scanner-confirmed-table
            :rows="$this->confirmed"
            :title="'Confirmed ('.count($this->confirmed).')'"
            empty="Scan EPCs that are on hand at the selected site."
            remove-method="removeConfirmed"
            id-key="epc_id"
        >
            <x-slot:headerActions>
                @if ($this->confirmed !== [])
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="clearConfirmed">
                        Clear list
                    </button>
                @endif
            </x-slot:headerActions>
        </x-scanner-confirmed-table>
    </x-scanner-desk>

    <x-filament-actions::modals />
</x-filament-panels::page>
