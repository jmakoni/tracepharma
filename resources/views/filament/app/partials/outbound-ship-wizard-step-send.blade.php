@php
    $showAtpGateNote = $showAtpGateNote ?? false;
@endphp
<div class="flex flex-col gap-4">
    <div class="form-control w-full gap-1.5">
        <label for="asn-number" class="label-text text-sm font-medium">ASN number *</label>
        <input id="asn-number" type="text" wire:model.live.debounce.300ms="asn_number" class="input input-bordered w-full" />
    </div>
    <div class="form-control w-full gap-1.5">
        <label for="expected-count" class="label-text text-sm font-medium">Expected units</label>
        <input id="expected-count" type="number" min="0" step="1" wire:model.live.debounce.300ms="expected_count" class="input input-bordered w-full" />
        <p class="text-xs text-base-content/70">
            From ASN/order qty when known.
            @if ($this->expectedCountGateRequiresPositiveQuantity())
                Live / hypercare / first-live-lot connections require a positive expected count (or an audited quantity-gate override) before send.
            @else
                Leave blank or 0 to skip the quantity gate for test/conformance connections.
            @endif
        </p>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="form-control w-full gap-1.5">
            <label for="customer-po" class="label-text text-sm font-medium">Customer PO *</label>
            <input id="customer-po" type="text" wire:model.live.debounce.300ms="customer_po" class="input input-bordered w-full" />
        </div>
        <div class="form-control w-full gap-1.5">
            <label for="invoice-number" class="label-text text-sm font-medium">Invoice number *</label>
            <input id="invoice-number" type="text" wire:model.live.debounce.300ms="invoice_number" class="input input-bordered w-full" />
        </div>
    </div>
    <p class="text-xs text-base-content/70">ASN is required. Also enter a customer PO or invoice (either one).</p>
    <div class="form-control w-full gap-1.5">
        <label for="shipment-reference" class="label-text text-sm font-medium">Shipment reference</label>
        <input id="shipment-reference" type="text" wire:model.live.debounce.300ms="shipment_reference" class="input input-bordered w-full" />
    </div>
    <div class="form-control w-full gap-1.5">
        <label for="dscsa-affirm" class="label cursor-pointer justify-start gap-3">
            <input
                id="dscsa-affirm"
                type="checkbox"
                wire:model.live="dscsa_affirm"
                class="checkbox checkbox-primary"
            />
            <span class="label-text">I affirm TI/TS (DSCSA transaction statement) *</span>
        </label>
        @unless ($this->dscsa_affirm)
            <p class="text-xs text-error">
                Required before this shipment can be sent. The affirmation is authored into the
                shipping EPCIS as the seller's transaction statement.
            </p>
        @endunless
    </div>
    <div class="form-control w-full gap-1.5">
        <label for="is-drop-shipment" class="label cursor-pointer justify-start gap-3">
            <input
                id="is-drop-shipment"
                type="checkbox"
                wire:model.live="is_drop_shipment"
                class="checkbox checkbox-primary"
            />
            <span class="label-text">Drop shipment</span>
        </label>
        <p class="text-xs text-base-content/70">
            When checked, outbound EPCIS includes the GS1 dropShipment indicator for the trading partner.
        </p>
    </div>

    @if ($showAtpGateNote && method_exists($this, 'atpOutboundGateDisabled') && $this->atpOutboundGateDisabled())
        <p class="text-xs text-warning" data-testid="atp-outbound-gate-disabled-send-note">
            ATP outbound gate is disabled — this send will not verify the destination's
            ATP license. You are affirming the customer is authorized.
        </p>
    @endif

    <div class="flex flex-wrap gap-2">
        <button type="button" wire:click="goToStep(2)" class="btn btn-ghost btn-sm">← Customer</button>
        <button type="button" wire:click="mountAction('saveReferences')" class="btn btn-primary btn-sm">Save references</button>
    </div>
</div>
