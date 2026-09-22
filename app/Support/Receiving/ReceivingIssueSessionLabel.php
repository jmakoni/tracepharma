<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;

/**
 * Picker / header identity for Receiving issues: PO and ASN first, session id
 * only when there is no real inbound order (scan-first).
 */
final class ReceivingIssueSessionLabel
{
    public static function for(ReceivingSession $session): string
    {
        $refs = self::orderRefs($session);
        $parts = [];

        if ($refs['po'] !== null) {
            $parts[] = $refs['po'];
        }

        if ($refs['asn'] !== null) {
            $parts[] = $refs['asn'];
        }

        if ($parts === [] && $session->getKey() !== null) {
            $parts[] = '#'.$session->getKey();
        }

        $partner = $session->tradingPartner?->name;
        if (filled($partner)) {
            $parts[] = (string) $partner;
        }

        $site = $session->site?->name;
        if (filled($site)) {
            $parts[] = (string) $site;
        }

        if ($session->completed_at !== null) {
            $parts[] = $session->completed_at->timezone(config('app.timezone'))->format('Y-m-d H:i');
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{po: ?string, asn: ?string}
     */
    public static function orderRefs(ReceivingSession $session): array
    {
        $shipment = ExpectedInboundOrderHeader::shipmentFor($session);

        return [
            'po' => self::displayPo($shipment),
            'asn' => self::displayAsn($shipment),
        ];
    }

    public static function displayPo(?InboundShipment $shipment): ?string
    {
        if ($shipment === null || ! filled($shipment->customer_po)) {
            return null;
        }

        return trim((string) $shipment->customer_po);
    }

    public static function displayAsn(?InboundShipment $shipment): ?string
    {
        if ($shipment === null || ! filled($shipment->asn_number)) {
            return null;
        }

        $asn = trim((string) $shipment->asn_number);
        if ($asn === '' || str_starts_with($asn, 'DOC:')) {
            return null;
        }

        return $asn;
    }
}
