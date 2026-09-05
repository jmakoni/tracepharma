<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\DscsaShippingExtensionParser;
use PHPUnit\Framework\Attributes\Test;
use SimpleXMLElement;
use Tests\TestCase;

class DscsaShippingExtensionParserTest extends TestCase
{
    #[Test]
    public function it_parses_direct_purchase_qualifier_statement_and_indirect_epcs_from_xml(): void
    {
        $xml = <<<'XML'
<extension>
  <sourceList/>
  <gs1ushc:directPurchase qualifier="PARTIALLY_DIRECT" xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns">
    <gs1ushc:directPurchaseStatement>Wholesaler direct purchase text.</gs1ushc:directPurchaseStatement>
    <gs1ushc:indirectPurchaseEPCs>
      <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
    </gs1ushc:indirectPurchaseEPCs>
  </gs1ushc:directPurchase>
  <gs1ushc:receivedDirectPurchaseFromPrevWhlsDist qualifier="ENTIRELY_DIRECT" xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns">
    <gs1ushc:receivedDirectPurchaseFromPrevWhlsDistStatement>Received prev wholesaler statement.</gs1ushc:receivedDirectPurchaseFromPrevWhlsDistStatement>
  </gs1ushc:receivedDirectPurchaseFromPrevWhlsDist>
</extension>
XML;

        $parsed = DscsaShippingExtensionParser::parseXmlExtension(new SimpleXMLElement($xml));

        $this->assertNotNull($parsed);
        $this->assertSame('PARTIALLY_DIRECT', $parsed->directPurchase?->qualifier);
        $this->assertSame('Wholesaler direct purchase text.', $parsed->directPurchase?->statement);
        $this->assertSame(
            ['urn:epc:id:sgtin:030116.0200116.10000082001560'],
            $parsed->directPurchase?->indirectEpcUris,
        );
        $this->assertSame('ENTIRELY_DIRECT', $parsed->receivedPrevWholesaler?->qualifier);
        $this->assertSame('Received prev wholesaler statement.', $parsed->receivedPrevWholesaler?->statement);
    }

    #[Test]
    public function it_parses_direct_purchase_from_json_array(): void
    {
        $parsed = DscsaShippingExtensionParser::parseJsonExtension([
            'directPurchase' => [
                'qualifier' => 'ENTIRELY_DIRECT',
                'directPurchaseStatement' => 'Inbound authored statement.',
            ],
        ]);

        $this->assertNotNull($parsed);
        $this->assertSame('ENTIRELY_DIRECT', $parsed->directPurchase?->qualifier);
        $this->assertSame('Inbound authored statement.', $parsed->directPurchase?->statement);
    }
}
