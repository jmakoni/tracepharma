<?php

namespace Tests\Unit\Support\Receiving;

use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;
use App\Support\Receiving\ExpectedInboundOrderHeader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExpectedInboundOrderHeaderTest extends TestCase
{
    #[Test]
    public function for_session_returns_null_without_shipment(): void
    {
        $session = new ReceivingSession;
        $session->setRelation('inboundShipment', null);
        $session->setRelation('document', null);

        $this->assertNull(ExpectedInboundOrderHeader::shipmentFor($session));
        $this->assertNull(ExpectedInboundOrderHeader::forSession($session));
    }

    #[Test]
    public function for_session_maps_shipment_rollups(): void
    {
        $shipment = new InboundShipment([
            'asn_number' => 'ASN-100',
            'customer_po' => 'PO-9',
            'status' => 'open',
            'expected_parent_count' => 4,
            'confirmed_parent_count' => 1,
            'expected_each_count' => 40,
            'confirmed_each_count' => 10,
        ]);

        $session = new ReceivingSession;
        $session->setRelation('inboundShipment', $shipment);
        $session->setRelation('document', null);

        $header = ExpectedInboundOrderHeader::forSession($session);

        $this->assertSame([
            'po' => 'PO-9',
            'asn' => 'ASN-100',
            'status' => 'open',
            'parents_confirmed' => 1,
            'parents_expected' => 4,
            'eaches_confirmed' => 10,
            'eaches_expected' => 40,
        ], $header);
    }
}
