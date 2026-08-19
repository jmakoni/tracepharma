<?php

namespace App\Support\Transferring;

use App\Models\Epcis\Epc;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;

/**
 * Whether an EPC is already confirmed/received on a different open/in-transit transfer.
 */
final class EpcOnAnotherOpenTransferringSession
{
    public function exists(Epc $epc, TransferringSession $current): bool
    {
        return $this->otherSession($epc, $current) !== null;
    }

    public function otherSession(Epc $epc, TransferringSession $current): ?TransferringSession
    {
        $line = TransferringScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereIn('status', ['confirmed', 'received'])
            ->whereHas('session', function ($query) use ($current): void {
                $query
                    ->whereIn('status', ['open', 'in_transit'])
                    ->whereKeyNot($current->getKey());
            })
            ->with(['session' => fn ($q) => $q->select(['id', 'status', 'from_site_id', 'to_site_id'])])
            ->orderByDesc('id')
            ->first();

        return $line?->session;
    }
}
