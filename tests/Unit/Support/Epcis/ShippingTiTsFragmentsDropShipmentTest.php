<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\ShippingTiTsFragments;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShippingTiTsFragmentsDropShipmentTest extends TestCase
{
    #[Test]
    public function drop_shipment_indicator_xml_emits_true_when_flagged(): void
    {
        $xml = ShippingTiTsFragments::dropShipmentIndicatorXml(true);

        $this->assertStringContainsString('<gs1ushc:dropShipment>true</gs1ushc:dropShipment>', $xml);
    }

    #[Test]
    public function drop_shipment_indicator_xml_emits_false_when_unflagged(): void
    {
        $xml = ShippingTiTsFragments::dropShipmentIndicatorXml(false);

        $this->assertStringContainsString('<gs1ushc:dropShipment>false</gs1ushc:dropShipment>', $xml);
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
