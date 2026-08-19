<?php

namespace App\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingSession;

/**
 * Locate an open transfer_receive session that still expects this EPC, or already
 * confirmed it at the same site (repair dual-write onto the transferring session).
 */
final class FindOpenTransferReceiveSessionExpectingEpc
{
    public function handle(Epc $epc, ?ReceivingSession $exclude = null, ?int $siteId = null): ?ReceivingSession
    {
        $query = ReceivingSession::query()
            ->where('session_kind', ReceivingSessionKind::TransferReceive)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('scanLines', function ($q) use ($epc): void {
                $q->where('epc_id', $epc->getKey())
                    ->whereIn('status', ['expected', 'confirmed']);
            })
            ->when($exclude !== null, fn ($q) => $q->whereKeyNot($exclude->getKey()))
            ->orderBy('id');

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        return $query->first();
    }
}
