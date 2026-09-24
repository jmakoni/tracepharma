<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\PedigreeXmlFragmentsToJsonLdEvents;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PedigreeXmlFragmentsToJsonLdEventsTest extends TestCase
{
    #[Test]
    public function it_replays_commission_and_pack_fragments_as_json_ld_events(): void
    {
        $commission = <<<'XML'
<ObjectEvent>
  <eventTime>2026-06-18T23:27:32.897Z</eventTime>
  <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
  <eventID>urn:uuid:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee</eventID>
  <epcList>
    <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
  </epcList>
  <action>ADD</action>
  <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
  <disposition>urn:epcglobal:cbv:disp:active</disposition>
  <readPoint>
    <id>urn:epc:id:sgln:030116.000000.0</id>
  </readPoint>
  <bizLocation>
    <id>urn:epc:id:sgln:030116.000000.0</id>
  </bizLocation>
  <extension>
    <ilmd>
      <cbvmda:lotNumber>606412T</cbvmda:lotNumber>
      <cbvmda:itemExpirationDate>2029-05-31</cbvmda:itemExpirationDate>
    </ilmd>
  </extension>
</ObjectEvent>
XML;

        $pack = <<<'XML'
<AggregationEvent>
  <eventTime>2026-07-15T19:24:20.000Z</eventTime>
  <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
  <parentID>urn:epc:id:sscc:030116.01001227052</parentID>
  <childEPCs>
    <epc>urn:epc:id:sgtin:030116.0200116.10000082001560</epc>
  </childEPCs>
  <action>ADD</action>
  <bizStep>urn:epcglobal:cbv:bizstep:packing</bizStep>
  <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>
  <readPoint>
    <id>urn:epc:id:sgln:030116.000000.0</id>
  </readPoint>
</AggregationEvent>
XML;

        $events = app(PedigreeXmlFragmentsToJsonLdEvents::class)->handle([$commission, $pack]);

        $this->assertCount(2, $events);
        $this->assertSame('ObjectEvent', $events[0]['type']);
        $this->assertSame('2026-06-18T23:27:32.897Z', $events[0]['eventTime']);
        $this->assertSame('urn:epcglobal:cbv:bizstep:commissioning', $events[0]['bizStep']);
        $this->assertSame(['urn:epc:id:sgtin:030116.0200116.10000082001560'], $events[0]['epcList']);
        $this->assertSame('606412T', $events[0]['ilmd']['lotNumber']);
        $this->assertSame('2029-05-31', $events[0]['ilmd']['itemExpirationDate']);
        $this->assertSame('urn:epc:id:sgln:030116.000000.0', $events[0]['readPoint']['id']);

        $this->assertSame('AggregationEvent', $events[1]['type']);
        $this->assertSame('urn:epcglobal:cbv:bizstep:packing', $events[1]['bizStep']);
        $this->assertSame('urn:epc:id:sscc:030116.01001227052', $events[1]['parentID']);
        $this->assertSame(['urn:epc:id:sgtin:030116.0200116.10000082001560'], $events[1]['childEPCs']);
    }

    #[Test]
    public function empty_fragments_yield_no_events(): void
    {
        $this->assertSame([], app(PedigreeXmlFragmentsToJsonLdEvents::class)->handle(['', '   ']));
    }
}
