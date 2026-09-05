<div class="flex flex-col gap-4">
    <div class="form-control w-full gap-1.5">
        <label for="customer-search" class="label-text text-sm font-medium">Customer</label>
        <div class="relative" wire:click.away="$set('customerDropdownOpen', false)">
            <div class="flex gap-2">
                <input
                    id="customer-search"
                    type="search"
                    autocomplete="off"
                    wire:model.live.debounce.300ms="customerSearch"
                    wire:focus="openCustomerDropdown"
                    class="input input-bordered w-full"
                    placeholder="Search company or ship-to address…"
                />
                @if ($this->ship_to_site_id || filled($this->customerSearch))
                    <button
                        type="button"
                        wire:click="clearShipToCustomer"
                        class="btn btn-ghost btn-sm shrink-0"
                        title="Clear customer"
                    >
                        Clear
                    </button>
                @endif
            </div>

            @if ($this->customerDropdownOpen && $this->customerSuggestions !== [])
                <ul
                    class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-base-300 bg-base-100 py-1 shadow-lg"
                    role="listbox"
                >
                    @foreach ($this->customerSuggestions as $suggestion)
                        <li role="option">
                            <button
                                type="button"
                                wire:click="selectShipToCustomer({{ (int) $suggestion['site_id'] }})"
                                class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left hover:bg-base-200"
                            >
                                <span class="text-sm font-medium">{{ $suggestion['company'] }}</span>
                                <span class="text-xs text-base-content/70">{{ $suggestion['address'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($summary = $this->selectedShipToSummary())
            <p class="text-xs text-base-content/70">
                Ship-to: {{ $summary['address'] }}
            </p>
        @endif
    </div>

    <input type="hidden" wire:model="ship_to_gln" />

    <div class="form-control w-full gap-1.5">
        <label for="outbound-connection" class="label-text text-sm font-medium">Outbound connection</label>
        <select
            id="outbound-connection"
            wire:model="outbound_connection_id"
            class="select select-bordered w-full"
        >
            <option value="">Default for partner</option>
            @foreach ($this->outboundConnectionOptions() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-wrap gap-2">
        <button type="button" wire:click="goToStep(1)" class="btn btn-ghost btn-sm">← Scan</button>
        <button type="button" wire:click="mountAction('saveParty')" class="btn btn-primary btn-sm">Save customer</button>
        <button type="button" wire:click="goToStep(3)" class="btn btn-outline btn-sm">Next: Send →</button>
    </div>
</div>
