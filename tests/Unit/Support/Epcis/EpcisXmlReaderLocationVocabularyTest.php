<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisXmlReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisXmlReaderLocationVocabularyTest extends TestCase
{
    #[Test]
    public function it_parses_location_vocabulary_into_named_gln_rows(): void
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
        $this->assertFileExists($fixture);

        $parsed = (new EpcisXmlReader)->parse($fixture);

        $this->assertArrayHasKey('locations', $parsed);
        $this->assertCount(4, $parsed['locations']);

        $byGln = collect($parsed['locations'])->keyBy('gln');

        $this->assertSame('Xttrium Laboratories, Inc.', $byGln['0301160000009']['name']);
        $this->assertSame('1200 E BUSINESS CENTER DR', $byGln['0301160000009']['street_address']);
        $this->assertSame('MOUNT PROSPECT', $byGln['0301160000009']['city']);
        $this->assertSame('IL', $byGln['0301160000009']['state']);
        $this->assertSame('60056-6041', $byGln['0301160000009']['postal_code']);
        $this->assertSame('US', $byGln['0301160000009']['country_code']);
        $this->assertSame('urn:epc:id:sgln:030116.000000.0', $byGln['0301160000009']['gln_uri']);
        $this->assertIsArray($byGln['0301160000009']['attributes_json']);
        $this->assertNotEmpty($byGln['0301160000009']['attributes_json']);
        $this->assertArrayHasKey('urn:epcglobal:cbv:mda#name', $byGln['0301160000009']['attributes_json']);
        $this->assertSame(
            'Xttrium Laboratories, Inc.',
            $byGln['0301160000009']['attributes_json']['urn:epcglobal:cbv:mda#name'],
        );

        $this->assertSame('Xttrium Glenview', $byGln['0301160000016']['name']);
        $this->assertSame('Cardinal Health - Corporate', $byGln['0096295000009']['name']);
        $this->assertSame('Cardinal Groveport', $byGln['0096295000993']['name']);
        $this->assertSame('Groveport', $byGln['0096295000993']['city']);
    }

    #[Test]
    public function it_keeps_non_sgln_location_ids_with_null_gln(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISHeader>
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:Location">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:id:gln:0301160000009">
                <attribute id="urn:epcglobal:cbv:mda#name">Non-SGLN Site</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
        </VocabularyList>
      </EPCISMasterData>
    </extension>
  </EPCISHeader>
  <EPCISBody><EventList/></EPCISBody>
</epcis:EPCISDocument>
XML;

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_loc_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $xml);

        try {
            $parsed = (new EpcisXmlReader)->parse($tmp);

            $this->assertCount(1, $parsed['locations']);
            $this->assertSame('urn:epc:id:gln:0301160000009', $parsed['locations'][0]['gln_uri']);
            $this->assertNull($parsed['locations'][0]['gln']);
            $this->assertSame('Non-SGLN Site', $parsed['locations'][0]['name']);
            $this->assertArrayHasKey('urn:epcglobal:cbv:mda#name', $parsed['locations'][0]['attributes_json']);
        } finally {
            @unlink($tmp);
        }
    }
}
