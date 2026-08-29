<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\ShippingTiTsFragments;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShippingTiTsFragmentsDropShipmentTest extends TestCase
{
    #[Test]
    public function drop_shipment_indicator_xml_emits_drop_shipment_when_flagged(): void
    {
        $xml = ShippingTiTsFragments::dropShipmentIndicatorXml(true);

        $this->assertNotSame('', $xml);
        $this->assertStringContainsString('dropShipment', $xml);
        $this->assertStringContainsString('gs1ushc:dropShipment', $xml);
    }

    #[Test]
    public function drop_shipment_indicator_xml_is_empty_when_unflagged(): void
    {
        $this->assertSame('', ShippingTiTsFragments::dropShipmentIndicatorXml(false));
    }

    #[Test]
    public function assert_drop_shipment_emitted_fails_closed_when_flag_on_but_xml_lacks_indicator(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dropShipment');

        ShippingTiTsFragments::assertDropShipmentEmitted(
            isDropShipment: true,
            payload: '<?xml version="1.0"?><epcis:EPCISDocument></epcis:EPCISDocument>',
        );
    }

    #[Test]
    public function assert_drop_shipment_emitted_is_noop_when_unflagged(): void
    {
        ShippingTiTsFragments::assertDropShipmentEmitted(
            isDropShipment: false,
            payload: '<?xml version="1.0"?><epcis:EPCISDocument></epcis:EPCISDocument>',
        );

        $this->assertTrue(true);
    }
}
