<?php

namespace Tests\Unit\Support\Epcis;

use App\Enums\EpcisGuideline;
use App\Support\Epcis\DetectDscsaGuidelineRelease;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectDscsaGuidelineReleaseTest extends TestCase
{
    #[Test]
    public function empty_payload_without_guideline_version_is_r12_not_ambiguous(): void
    {
        $detection = DetectDscsaGuidelineRelease::fromXml('');

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R12, $detection->release);
        $this->assertFalse($detection->lotOnly);
        $this->assertFalse(DetectDscsaGuidelineRelease::isR12LotOnlyShape($detection));
    }

    #[Test]
    public function missing_guideline_version_and_fda_ndc_11_is_r12(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R12, $detection->release);
    }

    #[Test]
    public function official_guideline_version_text_is_r13(): void
    {
        $xml = '<gs1ushc:guidelineVersion>GS1 US DSCSA R1.3</gs1ushc:guidelineVersion>';

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R13, $detection->release);
    }

    #[Test]
    public function us_fda_ndc_without_guideline_version_is_r13(): void
    {
        $xml = '<attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">US_FDA_NDC</attribute>';

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertSame(EpcisGuideline::R13, $detection->release);
    }

    #[Test]
    public function guideline_version_plus_fda_ndc_11_is_r13_not_mixed(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <gs1ushc:guidelineVersion>R1.3</gs1ushc:guidelineVersion>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R13, $detection->release);
    }

    #[Test]
    public function both_ndc_type_codes_are_mixed(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">US_FDA_NDC</attribute>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertTrue($detection->mixed);
        $this->assertNull($detection->release);
    }

    #[Test]
    public function boolean_and_qualifier_direct_purchase_are_mixed(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <gs1ushc:directPurchase>true</gs1ushc:directPurchase>
  <gs1ushc:directPurchase qualifier="ENTIRELY_DIRECT"><gs1ushc:directPurchaseStatement>x</gs1ushc:directPurchaseStatement></gs1ushc:directPurchase>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertTrue($detection->mixed);
    }

    #[Test]
    public function qualifier_only_is_r13(): void
    {
        $xml = '<gs1ushc:directPurchase qualifier="PARTIALLY_DIRECT"></gs1ushc:directPurchase>';

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R13, $detection->release);
    }

    #[Test]
    public function boolean_direct_purchase_only_is_r12(): void
    {
        $xml = '<gs1ushc:directPurchase>true</gs1ushc:directPurchase>';

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R12, $detection->release);
    }

    #[Test]
    public function drop_shipment_alone_is_r13(): void
    {
        $xml = '<gs1ushc:dropShipment>false</gs1ushc:dropShipment>';

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R13, $detection->release);
    }

    #[Test]
    public function drop_shipment_plus_boolean_direct_purchase_is_r13_not_mixed(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <gs1ushc:dropShipment>false</gs1ushc:dropShipment>
  <gs1ushc:directPurchase>true</gs1ushc:directPurchase>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R13, $detection->release);
        $this->assertFalse(DetectDscsaGuidelineRelease::isR12LotOnlyShape($detection));
    }

    #[Test]
    public function mixed_exclusive_constructs_are_not_r12_lot_only(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">US_FDA_NDC</attribute>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertTrue($detection->mixed);
        $this->assertFalse(DetectDscsaGuidelineRelease::isR12LotOnlyShape($detection));
    }

    #[Test]
    public function serialized_r12_with_sgtin_is_not_lot_only(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
  <epcList>
    <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
  </epcList>
  <ilmd>
    <lotNumber>606412T</lotNumber>
  </ilmd>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertSame(EpcisGuideline::R12, $detection->release);
        $this->assertFalse($detection->lotOnly);
        $this->assertFalse(DetectDscsaGuidelineRelease::isR12LotOnlyShape($detection));
    }

    #[Test]
    public function r12_quantity_class_payload_is_lot_only(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
  <quantityList>
    <quantityElement>
      <epcClass>urn:epc:class:lgtin:030116.0200116.606412T</epcClass>
      <quantity>12</quantity>
    </quantityElement>
  </quantityList>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertSame(EpcisGuideline::R12, $detection->release);
        $this->assertTrue($detection->lotOnly);
        $this->assertTrue(DetectDscsaGuidelineRelease::isR12LotOnlyShape($detection));
    }

    #[Test]
    public function guideline_version_epcis_1_3_substring_is_not_r13(): void
    {
        $xml = <<<'XML'
<EPCISDocument>
  <guidelineVersion>see EPCIS 1.2 / CBV 1.2</guidelineVersion>
  <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
</EPCISDocument>
XML;

        $detection = DetectDscsaGuidelineRelease::fromXml($xml);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R12, $detection->release);
    }

    #[Test]
    public function json_ld_guideline_version_is_r13(): void
    {
        $json = json_encode([
            'type' => 'EPCISDocument',
            'gs1ushc:guidelineVersion' => 'GS1 US DSCSA R1.3',
            'epcisBody' => ['eventList' => []],
        ], JSON_THROW_ON_ERROR);

        $detection = DetectDscsaGuidelineRelease::fromJson($json);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R13, $detection->release);
    }

    #[Test]
    public function json_ld_us_fda_ndc_is_r13(): void
    {
        $json = json_encode([
            'type' => 'EPCISDocument',
            'epcisBody' => [
                'eventList' => [[
                    'additionalTradeItemIdentificationTypeCode' => 'US_FDA_NDC',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $detection = DetectDscsaGuidelineRelease::fromJson($json);

        $this->assertFalse($detection->mixed);
        $this->assertSame(EpcisGuideline::R13, $detection->release);
    }

    #[Test]
    public function from_path_detects_json_ld_r13_payload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dscsa20_');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode([
            'type' => 'EPCISDocument',
            'gs1ushc:guidelineVersion' => 'GS1 US DSCSA R1.3',
        ], JSON_THROW_ON_ERROR));

        try {
            $detection = DetectDscsaGuidelineRelease::fromPath($path);

            $this->assertFalse($detection->mixed);
            $this->assertSame(EpcisGuideline::R13, $detection->release);
        } finally {
            @unlink($path);
        }
    }
}
