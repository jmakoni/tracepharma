<?php

namespace App\Support\Shipping;

use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;

/**
 * Whether an EPC is already confirmed on a ship order that still exclusively holds it:
 * open, in progress, or completed before shipping EPCIS was authored.
 */
final class EpcOnOpenShippingSession
{
    public function exists(Epc $epc, ?OutboundShippingSession $except = null): bool
    {
        return OutboundShippingScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereHas('session', function ($query) use ($except): void {
                $query->where(function ($inner): void {
                    $inner
                        ->whereIn('status', ['open', 'in_progress'])
                        ->orWhere(function ($completed): void {
                            $completed
                                ->where('status', 'completed')
                                ->whereNull('shipping_events_generated_at');
                        });
                });

                if ($except !== null) {
                    $query->whereKeyNot($except->getKey());
                }
            })
            ->exists();
    }
}
