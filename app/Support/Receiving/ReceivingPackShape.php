<?php

namespace App\Support\Receiving;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingSession;

/**
 * Classify logistics EPCs from inbound/transfer-ship AggregationEvents only.
 * Warehouse-only open links are not SOP shape, including when no session is passed.
 */
final class ReceivingPackShape
{
    public static function isLogisticsPalletSscc(Epc $epc, ?ReceivingSession $session = null): bool
    {
        if ($epc->epc_type !== 'sscc' || $session === null) {
            return false;
        }

        return self::inboundChildrenIncludeSscc(
            app(ResolveInboundAggregationChildEpcs::class)->childEpcIdsForParent($session, $epc),
        );
    }

    public static function isSaleableUnit(Epc $epc, ?ReceivingSession $session = null): bool
    {
        if ($epc->epc_type === 'sscc' || $session === null) {
            return false;
        }

        return app(ResolveInboundAggregationChildEpcs::class)
            ->childEpcIdsForParent($session, $epc) === [];
    }

    public static function isCasePack(Epc $epc, ?ReceivingSession $session = null): bool
    {
        if ($session === null) {
            return false;
        }

        $childIds = app(ResolveInboundAggregationChildEpcs::class)
            ->childEpcIdsForParent($session, $epc);

        if ($childIds === []) {
            return false;
        }

        if ($epc->epc_type === 'sscc') {
            return ! self::inboundChildrenIncludeSscc($childIds);
        }

        return true;
    }

    public static function openChildCount(Epc $epc): int
    {
        return (int) AggregationLink::query()
            ->where('parent_epc_id', $epc->getKey())
            ->whereNull('valid_to')
            ->count();
    }

    /**
     * @param  list<int>  $childIds
     */
    private static function inboundChildrenIncludeSscc(array $childIds): bool
    {
        if ($childIds === []) {
            return false;
        }

        return Epc::query()
            ->whereIn('id', $childIds)
            ->where('epc_type', 'sscc')
            ->exists();
    }
}
