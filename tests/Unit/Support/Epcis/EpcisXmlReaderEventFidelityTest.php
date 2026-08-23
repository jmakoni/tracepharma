<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisXmlReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisXmlReaderEventFidelityTest extends TestCase
{
    #[Test]
    public function it_extracts_event_id_record_time_and_error_declaration(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-06-18T23:27:32.897Z</eventTime>
        <recordTime>2026-06-18T23:28:00.000Z</recordTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <eventID>urn:uuid:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee</eventID>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
        </epcList>
        <action>ADD</action>
        <errorDeclaration>
          <declarationTime>2026-06-19T01:00:00.000Z</declarationTime>
          <reason>urn:epcglobal:cbv:er:incorrect_data</reason>
          <correctiveEventIDs>
            <correctiveEventID>urn:uuid:11111111-2222-3333-4444-555555555555</correctiveEventID>
          </correctiveEventIDs>
        </errorDeclaration>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $path = $this->writeTempXml($xml);
        $parsed = (new EpcisXmlReader)->parse($path);
        @unlink($path);

        $this->assertCount(1, $parsed['events']);
        $event = $parsed['events'][0];

        $this->assertSame('urn:uuid:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $event['event_id']);
        $this->assertSame('2026-06-18T23:28:00.000Z', $event['record_time']);
        $this->assertIsArray($event['error_declaration']);
        $this->assertSame('2026-06-19T01:00:00.000Z', $event['error_declaration']['declaration_time']);
        $this->assertSame('urn:epcglobal:cbv:er:incorrect_data', $event['error_declaration']['reason']);
        $this->assertSame(
            ['urn:uuid:11111111-2222-3333-4444-555555555555'],
            $event['error_declaration']['corrective_event_ids'],
        );
        $this->assertNotEmpty($event['error_declaration']['xml']);
    }

    #[Test]
    public function it_parses_transformation_input_and_output_epc_lists(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <TransformationEvent>
        <eventTime>2026-07-01T12:00:00.000Z</eventTime>
        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
        <eventID>urn:uuid:transf-0001-0002-0003-000000000001</eventID>
        <inputEPCList>
          <epc>urn:epc:id:sgtin:030116.0200116.111</epc>
        </inputEPCList>
        <outputEPCList>
          <epc>urn:epc:id:sgtin:030116.0200116.222</epc>
        </outputEPCList>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
      </TransformationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $path = $this->writeTempXml($xml);
        $parsed = (new EpcisXmlReader)->parse($path);
        @unlink($path);

        $this->assertCount(1, $parsed['events']);
        $event = $parsed['events'][0];

        $this->assertSame('TransformationEvent', $event['event_type']);
        $this->assertSame('urn:uuid:transf-0001-0002-0003-000000000001', $event['event_id']);

        $roles = collect($event['epcs'])->pluck('role', 'uri');
        $this->assertSame('inputEPC', $roles['urn:epc:id:sgtin:030116.0200116.111']);
        $this->assertSame('outputEPC', $roles['urn:epc:id:sgtin:030116.0200116.222']);
    }

    #[Test]
    public function aggregation_delete_still_returns_action_delete(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <AggregationEvent>
        <eventTime>2026-07-15T19:24:20.000Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <parentID>urn:epc:id:sscc:030116.01001227052</parentID>
        <childEPCs>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
        </childEPCs>
        <action>DELETE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:unpacking</bizStep>
      </AggregationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $path = $this->writeTempXml($xml);
        $parsed = (new EpcisXmlReader)->parse($path);
        @unlink($path);

        $this->assertCount(1, $parsed['events']);
        $this->assertSame('AggregationEvent', $parsed['events'][0]['event_type']);
        $this->assertSame('DELETE', $parsed['events'][0]['action']);
    }

    #[Test]
    public function parse_header_and_stream_yields_events_and_returns_header(): void
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $streamed = [];
        $header = (new EpcisXmlReader)->parseHeaderAndStream($fixture, function (array $event) use (&$streamed): void {
            $streamed[] = $event;
        });

        $this->assertSame(3, $header['events_streamed']);
        $this->assertCount(3, $streamed);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $header['document_uuid']);
        $this->assertTrue($header['dscsa_affirm']);
        $this->assertSame([], $header['dropped_epc_uris']);
        $this->assertArrayNotHasKey('events', $header);

        $buffered = (new EpcisXmlReader)->parse($fixture);
        $this->assertCount(3, $buffered['events']);
        $this->assertSame($streamed[0]['event_type'], $buffered['events'][0]['event_type']);
        $this->assertArrayNotHasKey('events_streamed', $buffered);
    }

    #[Test]
    public function it_parses_root_level_source_and_destination_lists(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-07-15T20:00:00.000Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
        </epcList>
        <action>OBSERVE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:shipping</bizStep>
        <sourceList>
          <source type="urn:epcglobal:cbv:sdt:owning_party">urn:epc:id:sgln:030116.000000.0</source>
        </sourceList>
        <destinationList>
          <destination type="urn:epcglobal:cbv:sdt:owning_party">urn:epc:id:sgln:009629.500000.0</destination>
        </destinationList>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $path = $this->writeTempXml($xml);
        $parsed = (new EpcisXmlReader)->parse($path);
        @unlink($path);

        $parties = collect($parsed['events'][0]['parties']);
        $this->assertCount(2, $parties);
        $this->assertTrue($parties->contains(fn (array $p): bool => $p['party_role'] === 'source'
            && $p['gln_uri'] === 'urn:epc:id:sgln:030116.000000.0'
            && $p['source_dest_type'] === 'owning_party'
            && $p['type_uri'] === 'urn:epcglobal:cbv:sdt:owning_party'));
        $this->assertTrue($parties->contains(fn (array $p): bool => $p['party_role'] === 'destination'
            && $p['gln_uri'] === 'urn:epc:id:sgln:009629.500000.0'
            && $p['type_uri'] === 'urn:epcglobal:cbv:sdt:owning_party'));
    }

    #[Test]
    public function it_parses_ilmd_manufacturing_best_before_and_extra_fields(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" xmlns:cbvmda="urn:epcglobal:cbv:mda" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-06-18T23:27:32.897Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
        </epcList>
        <action>ADD</action>
        <extension>
          <ilmd>
            <cbvmda:lotNumber>LOT99</cbvmda:lotNumber>
            <cbvmda:itemExpirationDate>2029-05-31</cbvmda:itemExpirationDate>
            <cbvmda:manufacturingDate>2026-01-15</cbvmda:manufacturingDate>
            <cbvmda:bestBeforeDate>2029-04-30</cbvmda:bestBeforeDate>
            <cbvmda:additionalId>NDC-00116</cbvmda:additionalId>
            <cbvmda:sellByDate>2029-03-31</cbvmda:sellByDate>
          </ilmd>
        </extension>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $path = $this->writeTempXml($xml);
        $parsed = (new EpcisXmlReader)->parse($path);
        @unlink($path);

        $ilmd = $parsed['events'][0]['ilmd'];
        $this->assertSame('LOT99', $ilmd['lot_number']);
        $this->assertSame('2029-05-31', $ilmd['expiry_date']);
        $this->assertSame('2026-01-15', $ilmd['manufacturing_date']);
        $this->assertSame('2029-04-30', $ilmd['best_before_date']);
        $this->assertSame('NDC-00116', $ilmd['additional_id']);
        $this->assertSame(['sellByDate' => '2029-03-31'], $ilmd['extra_json']);
    }

    #[Test]
    public function it_returns_quantities_and_attaches_to_matching_epcs(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <AggregationEvent>
        <eventTime>2026-07-15T19:24:20.000Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <parentID>urn:epc:id:sscc:030116.01001227052</parentID>
        <childEPCs>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
        </childEPCs>
        <action>ADD</action>
        <childQuantityList>
          <quantityElement>
            <epcClass>urn:epc:idpat:sgtin:030116.0200116.*</epcClass>
            <quantity>1</quantity>
            <uom>EA</uom>
          </quantityElement>
        </childQuantityList>
      </AggregationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $path = $this->writeTempXml($xml);
        $parsed = (new EpcisXmlReader)->parse($path);
        @unlink($path);

        $event = $parsed['events'][0];
        $this->assertCount(1, $event['quantities']);
        $this->assertSame(1.0, $event['quantities'][0]['quantity']);
        $this->assertSame('EA', $event['quantities'][0]['uom']);
        $this->assertSame('childQuantityList', $event['quantities'][0]['role']);

        $child = collect($event['epcs'])->firstWhere('role', 'childEPC');
        $this->assertNotNull($child);
        $this->assertSame(1.0, $child['quantity']);
        $this->assertSame('EA', $child['uom']);
        $this->assertSame([], $event['class_quantities']);
    }

    #[Test]
    public function it_returns_unmatched_class_quantities_and_extension_fields(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-07-15T20:00:00.000Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
        </epcList>
        <action>OBSERVE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:shipping</bizStep>
        <persistentDisposition>
          <set>urn:epcglobal:cbv:disp:in_transit</set>
          <unset>urn:epcglobal:cbv:disp:active</unset>
        </persistentDisposition>
        <quantityList>
          <quantityElement>
            <epcClass>urn:epc:idpat:sgtin:0614141.107346.*</epcClass>
            <quantity>24</quantity>
            <uom>EA</uom>
          </quantityElement>
        </quantityList>
        <extension>
          <sourceList>
            <source type="urn:epcglobal:cbv:sdt:owning_party">urn:epc:id:sgln:030116.000000.0</source>
          </sourceList>
          <customVendorFlag>alpha</customVendorFlag>
          <customNested>
            <inner>beta</inner>
          </customNested>
        </extension>
      </ObjectEvent>
      <TransformationEvent>
        <eventTime>2026-07-01T12:00:00.000Z</eventTime>
        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
        <transformationID>urn:uuid:transf-id-0001</transformationID>
        <inputEPCList>
          <epc>urn:epc:id:sgtin:030116.0200116.111</epc>
        </inputEPCList>
        <outputEPCList>
          <epc>urn:epc:id:sgtin:030116.0200116.222</epc>
        </outputEPCList>
      </TransformationEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $path = $this->writeTempXml($xml);
        $parsed = (new EpcisXmlReader)->parse($path);
        @unlink($path);

        $objectEvent = $parsed['events'][0];
        $this->assertSame(
            [
                'set' => ['urn:epcglobal:cbv:disp:in_transit'],
                'unset' => ['urn:epcglobal:cbv:disp:active'],
            ],
            $objectEvent['persistent_disposition'],
        );
        $this->assertCount(1, $objectEvent['class_quantities']);
        $this->assertSame('quantityList', $objectEvent['class_quantities'][0]['role']);
        $this->assertSame('urn:epc:idpat:sgtin:0614141.107346.*', $objectEvent['class_quantities'][0]['epc_class']);
        $this->assertSame(24.0, $objectEvent['class_quantities'][0]['quantity']);
        $this->assertSame('EA', $objectEvent['class_quantities'][0]['uom']);
        $this->assertSame('alpha', $objectEvent['extension_json']['customVendorFlag'] ?? null);
        $this->assertSame(['inner' => 'beta'], $objectEvent['extension_json']['customNested'] ?? null);
        $this->assertArrayNotHasKey('sourceList', $objectEvent['extension_json'] ?? []);

        $transformation = $parsed['events'][1];
        $this->assertSame('urn:uuid:transf-id-0001', $transformation['transformation_id']);
    }

    private function writeTempXml(string $xml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epcis_unit_');
        $this->assertNotFalse($path);
        file_put_contents($path, $xml);

        return $path;
    }
}
