<?php

namespace App\Support\Transferring;

use App\Models\Epcis\Epc;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;

/**
 * Whether an EPC is already confirmed/received on an open or in-transit transfer.
 */
final class EpcOnOpenTransferringSession
{
    public function exists(Epc $epc, ?TransferringSession $except = null): bool
    {
        return TransferringScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereIn('status', ['confirmed', 'received'])
            ->whereHas('session', function ($query) use ($except): void {
                $query->whereIn('status', ['open', 'in_transit']);

                if ($except !== null) {
                    $query->whereKeyNot($except->getKey());
                }
            })
            ->exists();
    }
}
