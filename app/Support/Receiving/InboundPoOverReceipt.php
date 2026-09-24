<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Collection;

/**
 * Warn when this receipt looks over relative to known ASNs on the same PO.
 * Does not file a PO-level shortage — the claim unit stays this shipment.
 */
final class InboundPoOverReceipt
{
    public static function warningFor(ReceivingSession $session): ?string
    {
        $shipment = ExpectedInboundOrderHeader::shipmentFor($session);
        if ($shipment === null) {
            return null;
        }

        $siblings = $shipment->exists
            ? self::siblings($shipment)
            : collect();

        return self::forShipment($shipment, $siblings);
    }

    /**
     * @param  Collection<int, InboundShipment>  $siblingShipments
     */
    public static function forShipment(InboundShipment $shipment, Collection $siblingShipments): ?string
    {
        $po = ReceivingIssueSessionLabel::displayPo($shipment);
        if ($po === null) {
            return null;
        }

        $unexpected = (int) $shipment->unexpected_count;
        $confirmed = (int) $shipment->confirmed_parent_count;
        $expected = (int) $shipment->expected_parent_count;

        foreach ($siblingShipments as $sibling) {
            $confirmed += (int) $sibling->confirmed_parent_count;
            $expected += (int) $sibling->expected_parent_count;
        }

        $overKnownAsns = $confirmed > $expected;
        if ($unexpected === 0 && ! $overKnownAsns) {
            return null;
        }

        $siblingAsns = $siblingShipments
            ->map(fn (InboundShipment $sibling): ?string => ReceivingIssueSessionLabel::displayAsn($sibling))
            ->filter()
            ->unique()
            ->values();

        $lead = 'PO '.$po;
        if ($siblingAsns->isNotEmpty()) {
            $lead .= ': also '.$siblingAsns->implode(', ');
        }

        $facts = [];
        if ($unexpected > 0) {
            $facts[] = sprintf('This ASN has %d unexpected', $unexpected);
        }
        if ($overKnownAsns) {
            $facts[] = sprintf('%d/%d parents confirmed across known ASNs', $confirmed, $expected);
        }

        return $lead.'. '.implode('. ', $facts).'. File overage against this shipment, not the PO.';
    }

    /**
     * @return Collection<int, InboundShipment>
     */
    private static function siblings(InboundShipment $shipment): Collection
    {
        $po = ReceivingIssueSessionLabel::displayPo($shipment);
        if ($po === null || $shipment->getKey() === null) {
            return collect();
        }

        return InboundShipment::query()
            ->where('trading_partner_key', (int) $shipment->trading_partner_key)
            ->where('customer_po', $po)
            ->whereKeyNot($shipment->getKey())
            ->where('status', '!=', 'cancelled')
            ->get();
    }
}
