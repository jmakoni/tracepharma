<?php

namespace App\Support\Shipping;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;

/**
 * Detect when confirmed ship lines still sit under an open aggregation parent that
 * is not itself a confirmed line on the same ship order (unexpected vs outermost).
 */
final class DetectOpenParentHierarchyOnShip
{
    /**
     * @return array{
     *     unexpected: bool,
     *     open_parent_epc_ids: list<int>,
     *     affected_child_epc_ids: list<int>
     * }
     */
    public function handle(OutboundShippingSession $session): array
    {
        $confirmedIds = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($confirmedIds === []) {
            return [
                'unexpected' => false,
                'open_parent_epc_ids' => [],
                'affected_child_epc_ids' => [],
            ];
        }

        $confirmedSet = array_fill_keys($confirmedIds, true);

        $links = AggregationLink::query()
            ->whereIn('child_epc_id', $confirmedIds)
            ->whereNull('valid_to')
            ->get(['parent_epc_id', 'child_epc_id']);

        $openParents = [];
        $affectedChildren = [];

        foreach ($links as $link) {
            $parentId = (int) $link->parent_epc_id;
            if (isset($confirmedSet[$parentId])) {
                continue;
            }

            $openParents[$parentId] = true;
            $affectedChildren[(int) $link->child_epc_id] = true;
        }

        return [
            'unexpected' => $openParents !== [],
            'open_parent_epc_ids' => array_map('intval', array_keys($openParents)),
            'affected_child_epc_ids' => array_map('intval', array_keys($affectedChildren)),
        ];
    }

    /**
     * Human-readable blocker when the session's confirmed set has an open parent gap.
     */
    public function blockerMessage(OutboundShippingSession $session): ?string
    {
        $detection = $this->handle($session);

        if (! ($detection['unexpected'] ?? false)) {
            return null;
        }

        $childCount = count($detection['affected_child_epc_ids'] ?? []);

        return sprintf(
            '%d confirmed line(s) have an open aggregation parent that is not on this ship order — scan the outermost container (SSCC) before sending.',
            $childCount,
        );
    }

    /**
     * Whether a unit about to be confirmed sits under an open parent not on the order.
     */
    public function unexpectedParentForEpc(OutboundShippingSession $session, Epc $epc): ?int
    {
        $link = AggregationLink::query()
            ->where('child_epc_id', $epc->getKey())
            ->whereNull('valid_to')
            ->first(['parent_epc_id']);

        if ($link === null) {
            return null;
        }

        $parentId = (int) $link->parent_epc_id;

        $parentConfirmedOnSession = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->where('epc_id', $parentId)
            ->exists();

        return $parentConfirmedOnSession ? null : $parentId;
    }
}
