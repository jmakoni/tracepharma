<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\InboundShipment;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Receiving\ReceivingPolicy;
use Illuminate\Support\Facades\Schema;

/**
 * Attach in-custody EPCs to their unique open ASN expected list.
 *
 * File receive and Scan In both author receiving events. Those events are
 * custody. Remaining-expected (Partially Received / Received) follows that
 * list, so custody on an SSCC that belongs to one open inbound shipment
 * must stamp the line — receive path does not matter.
 */
final class ReconcileInboundExpectedLinesFromCustody
{
    public function __construct(
        private readonly EpcCustodyGate $custodyGate,
    ) {}

    public function handle(?InboundShipment $shipment): int
    {
        if ($shipment === null || ! Schema::hasTable('inbound_expected_lines')) {
            return 0;
        }

        if ((string) $shipment->status === 'cancelled') {
            return 0;
        }

        $lines = InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipment->getKey())
            ->where('status', 'expected')
            ->whereNotNull('epc_id')
            ->get();

        if ($lines->isEmpty()) {
            return 0;
        }

        $epcIds = $lines->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $inCustody = array_fill_keys($this->custodyGate->epcIdsInCustody($epcIds), true);

        if ($inCustody === []) {
            return 0;
        }

        $autoChildren = ReceivingPolicy::forTenant(tenant())->defaultAutoConfirmChildren();
        $now = now();
        $stamped = 0;
        $shipmentId = (int) $shipment->getKey();

        foreach ($lines as $line) {
            $epcId = (int) $line->epc_id;
            if (! isset($inCustody[$epcId])) {
                continue;
            }

            if (! $this->isUniqueOpenShipmentForEpc($epcId, $shipmentId)) {
                continue;
            }

            $line->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => $now,
                'claimed_receiving_session_id' => null,
            ])->save();
            $stamped++;

            if ($autoChildren && $line->line_role === 'parent') {
                $stamped += $this->stampExpectedChildren($shipmentId, $epcId, $now);
            }
        }

        if ($stamped > 0) {
            $shipment->refreshRollups();
            $shipment->markCompleteIfDone();
        }

        return $stamped;
    }

    /**
     * @param  list<int>  $epcIds
     */
    public function handleForEpcIds(array $epcIds): int
    {
        $epcIds = array_values(array_unique(array_filter(array_map('intval', $epcIds))));
        if ($epcIds === [] || ! Schema::hasTable('inbound_expected_lines')) {
            return 0;
        }

        $shipmentIds = InboundExpectedLine::query()
            ->whereIn('epc_id', $epcIds)
            ->where('status', 'expected')
            ->whereHas('inboundShipment', function ($query): void {
                $query->whereIn('status', ['expected', 'open']);
            })
            ->pluck('inbound_shipment_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $total = 0;
        foreach ($shipmentIds as $shipmentId) {
            $shipment = InboundShipment::query()->find($shipmentId);
            if ($shipment !== null) {
                $total += $this->handle($shipment);
            }
        }

        return $total;
    }

    private function isUniqueOpenShipmentForEpc(int $epcId, int $shipmentId): bool
    {
        $ids = InboundExpectedLine::query()
            ->where('epc_id', $epcId)
            ->whereIn('status', ['expected', 'confirmed'])
            ->whereHas('inboundShipment', function ($query): void {
                $query->whereIn('status', ['expected', 'open']);
            })
            ->distinct()
            ->pluck('inbound_shipment_id')
            ->map(fn ($id): int => (int) $id)
            ->unique();

        return $ids->count() === 1 && $ids->first() === $shipmentId;
    }

    private function stampExpectedChildren(int $shipmentId, int $parentEpcId, mixed $now): int
    {
        return InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->where('parent_epc_id', $parentEpcId)
            ->where('status', 'expected')
            ->update([
                'status' => 'confirmed',
                'confirmed_at' => $now,
                'claimed_receiving_session_id' => null,
                'updated_at' => $now,
            ]);
    }
}
