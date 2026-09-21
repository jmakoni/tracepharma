<?php

namespace Tests\Unit\Support\Epcis;

use App\Enums\EpcisGuideline;
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

    #[Test]
    public function r12_omits_guideline_version_and_drop_shipment(): void
    {
        $this->assertSame('', ShippingTiTsFragments::guidelineVersionXml(EpcisGuideline::R12));
        $this->assertSame('', ShippingTiTsFragments::dropShipmentIndicatorXml(true, '    ', EpcisGuideline::R12));
        $this->assertSame('', ShippingTiTsFragments::dropShipmentIndicatorXml(false, '    ', EpcisGuideline::R12));
    }

    #[Test]
    public function r13_emits_official_guideline_version_text(): void
    {
        $xml = ShippingTiTsFragments::guidelineVersionXml(EpcisGuideline::R13);

        $this->assertStringContainsString(
            '<gs1ushc:guidelineVersion>GS1 US DSCSA R1.3</gs1ushc:guidelineVersion>',
            $xml,
        );
    }

    #[Test]
    public function r12_direct_purchase_is_boolean_true_not_qualifier(): void
    {
        $xml = ShippingTiTsFragments::directPurchaseXml('Seller statement', EpcisGuideline::R12);

        $this->assertStringContainsString('<gs1ushc:directPurchase>true</gs1ushc:directPurchase>', $xml);
        $this->assertStringNotContainsString('qualifier=', $xml);
        $this->assertStringNotContainsString('ENTIRELY_DIRECT', $xml);
    }

    #[Test]
    public function r13_partially_direct_includes_indirect_purchase_epcs(): void
    {
        $xml = ShippingTiTsFragments::directPurchaseXml(
            'Seller statement',
            EpcisGuideline::R13,
            'PARTIALLY_DIRECT',
            ['urn:epc:id:sgtin:030116.0200116.10000082001560'],
        );

        $this->assertStringContainsString('qualifier="PARTIALLY_DIRECT"', $xml);
        $this->assertStringContainsString('<gs1ushc:indirectPurchaseEPCs>', $xml);
        $this->assertStringContainsString('urn:epc:id:sgtin:030116.0200116.10000082001560', $xml);
    }

    #[Test]
    public function r13_received_prev_wholesaler_emits_entirely_direct(): void
    {
        $xml = ShippingTiTsFragments::receivedPrevWholesalerXml(
            'Seller affirms receipt of directly purchased statement from previous wholesaler distributor for the indicated product(s).',
        );

        $this->assertStringContainsString('receivedDirectPurchaseFromPrevWhlsDist', $xml);
        $this->assertStringContainsString('qualifier="ENTIRELY_DIRECT"', $xml);
        $this->assertStringContainsString('previous wholesaler distributor', $xml);
    }

    #[Test]
    public function r13_sbdh_authority_is_gs1(): void
    {
        $xml = ShippingTiTsFragments::sbdhXml(
            senderGln: '0301160000009',
            receiverGln: '0096295000009',
            instanceId: '11111111-2222-3333-4444-555555555555',
            creationDate: '2026-07-15T20:15:49.056Z',
            guideline: EpcisGuideline::R13,
        );

        $this->assertStringContainsString('Authority="GS1"', $xml);
        $this->assertStringNotContainsString('Authority="GLN"', $xml);
        $this->assertStringContainsString(
            '<sbdh:Identifier Authority="GS1">0301160000009</sbdh:Identifier>',
            $xml,
        );
    }

    #[Test]
    public function r12_sbdh_authority_is_gln(): void
    {
        $xml = ShippingTiTsFragments::sbdhXml(
            senderGln: '0301160000009',
            receiverGln: '0096295000009',
            instanceId: '11111111-2222-3333-4444-555555555555',
            creationDate: '2026-07-15T20:15:49.056Z',
            guideline: EpcisGuideline::R12,
        );

        $this->assertStringContainsString('Authority="GLN"', $xml);
        $this->assertStringNotContainsString('Authority="GS1"', $xml);
    }

    #[Test]
    public function drop_shipment_to_r12_partner_fails_closed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GS1 US DSCSA guideline R1.3');

        ShippingTiTsFragments::assertDropShipmentEmitted(
            isDropShipment: true,
            payload: '<?xml version="1.0"?><epcis:EPCISDocument></epcis:EPCISDocument>',
            guideline: EpcisGuideline::R12,
        );
    }
}
