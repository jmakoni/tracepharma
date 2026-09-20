<?php

namespace App\Support\Transferring;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Support\Shipping\DetectOpenParentHierarchyOnShip;

/**
 * Mirror {@see DetectOpenParentHierarchyOnShip} for transfer origin scans.
 *
 * Block inner scans only when an outer SSCC is on this transfer session but not yet confirmed.
 * Lone SGTIN transfers (no parent line on the session) must confirm even if warehouse links exist.
 */
final class DetectOpenParentHierarchyOnTransfer
{
    public function unexpectedParentForEpc(TransferringSession $session, Epc $epc): ?int
    {
        $link = AggregationLink::query()
            ->where('child_epc_id', $epc->getKey())
            ->whereNull('valid_to')
            ->first(['parent_epc_id']);

        if ($link === null) {
            return null;
        }

        $parentId = (int) $link->parent_epc_id;

        $parentLine = TransferringScanLine::query()
            ->where('transferring_session_id', $session->getKey())
            ->where('epc_id', $parentId)
            ->first(['status']);

        if ($parentLine === null) {
            return null;
        }

        return $parentLine->status === 'confirmed' ? null : $parentId;
    }
}
