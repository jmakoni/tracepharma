<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;

/**
 * Serial already confirmed on receive, or already shipped / sold.
 */
final class ReceiveDuplicateSerial
{
    public function __construct(
        private readonly EpcExclusiveSessionGate $exclusiveGate,
    ) {}

    public function reason(ReceivingSession $session, Epc $epc): ?string
    {
        $epcId = (int) $epc->getKey();

        $onOtherReceive = ReceivingScanLine::query()
            ->where('epc_id', $epcId)
            ->where('status', 'confirmed')
            ->where('receiving_session_id', '!=', $session->getKey())
            ->whereHas('session', function ($sessions): void {
                $sessions->whereIn('status', ['open', 'in_progress', 'completed']);
            })
            ->exists();
        if ($onOtherReceive) {
            return 'already_received';
        }

        $block = $this->exclusiveGate->checkScannedEpc($epc, ExclusiveSessionContext::forReceiving($session));
        if ($block !== null) {
            return $block->effect;
        }

        if (OutboundShippingScanLine::query()
            ->where('epc_id', $epcId)
            ->where('status', 'confirmed')
            ->exists()
        ) {
            return 'already_shipped';
        }

        return null;
    }
}
