<?php

namespace App\Support\Receiving;

use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\Schema;

/**
 * Compact expected-order (InboundShipment) header for receive HUD / Scan In.
 */
final class ExpectedInboundOrderHeader
{
    public static function shipmentFor(ReceivingSession $session): ?InboundShipment
    {
        if ($session->relationLoaded('inboundShipment') && $session->inboundShipment instanceof InboundShipment) {
            return $session->inboundShipment;
        }

        if ($session->relationLoaded('document')) {
            $document = $session->document;
            if ($document !== null) {
                if ($document->relationLoaded('inboundShipment') && $document->inboundShipment instanceof InboundShipment) {
                    return $document->inboundShipment;
                }
            }
        }

        // Both relations already resolved with no shipment — avoid Schema/DB in unit tests.
        if ($session->relationLoaded('inboundShipment') && $session->relationLoaded('document')) {
            return null;
        }

        if (! Schema::hasTable('inbound_shipments')) {
            return null;
        }

        $session->loadMissing(['inboundShipment', 'document.inboundShipment']);

        return $session->inboundShipment ?? $session->document?->inboundShipment;
    }

    /**
     * @return array{
     *     po: ?string,
     *     asn: ?string,
     *     status: string,
     *     parents_confirmed: int,
     *     parents_expected: int,
     *     eaches_confirmed: int,
     *     eaches_expected: int
     * }|null
     */
    public static function forSession(ReceivingSession $session): ?array
    {
        $shipment = self::shipmentFor($session);
        if ($shipment === null) {
            return null;
        }

        if (
            (int) $shipment->expected_parent_count === 0
            && (int) $shipment->expected_each_count === 0
            && Schema::hasTable('inbound_expected_lines')
            && $shipment->expectedLines()->exists()
        ) {
            $shipment->refreshRollups();
        }

        return [
            'po' => filled($shipment->customer_po) ? (string) $shipment->customer_po : null,
            'asn' => filled($shipment->asn_number) ? (string) $shipment->asn_number : null,
            'status' => (string) ($shipment->status ?? 'open'),
            'parents_confirmed' => (int) $shipment->confirmed_parent_count,
            'parents_expected' => (int) $shipment->expected_parent_count,
            'eaches_confirmed' => (int) $shipment->confirmed_each_count,
            'eaches_expected' => (int) $shipment->expected_each_count,
        ];
    }

    /**
     * Recompute shipment rollups after session work. Never forces complete while
     * expected lines remain — {@see InboundShipment::refreshRollups()}.
     */
    public static function refreshShipmentRollups(ReceivingSession $session): void
    {
        $shipment = self::shipmentFor($session);
        if ($shipment === null || ! Schema::hasTable('inbound_expected_lines')) {
            return;
        }

        $shipment->refreshRollups();
    }
}
