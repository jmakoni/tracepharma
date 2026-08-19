<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\InboundEpcisReceiver;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Multi-partner routing decides whose file this is from the sender GLN, so reading it
 * out of the SBDH — rather than off the first 13 digits that look the part — is what
 * keeps one partner's shipment from landing under another partner's name.
 */
class InboundEpcisReceiverSenderGlnTest extends TestCase
{
    #[Test]
    public function normalize_filename_strips_path_traversal(): void
    {
        $method = new ReflectionMethod(InboundEpcisReceiver::class, 'normalizeFilename');
        $method->setAccessible(true);
        $receiver = app(InboundEpcisReceiver::class);

        $filename = $method->invoke($receiver, '../../etc/passwd');

        $this->assertSame('passwd.xml', $filename);
    }

    #[Test]
    public function it_reads_the_sender_gln_from_the_sbdh(): void
    {
        $this->assertSame('0614141000005', $this->senderGln($this->documentWithSbdh('0614141000005')));
    }

    #[Test]
    public function it_reads_an_sbdh_sender_stated_as_an_sgln(): void
    {
        $this->assertSame(
            '0614141000005',
            $this->senderGln($this->documentWithSbdh('urn:epc:id:sgln:0614141.00000.0')),
        );
    }

    #[Test]
    public function it_does_not_mistake_the_destination_for_the_sender(): void
    {
        // The old regex took the first 13 digits inside anything named like a source,
        // which on this event is the buyer the goods are headed to.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader">
      <sbdh:Sender><sbdh:Identifier Authority="GS1">0614141000005</sbdh:Identifier></sbdh:Sender>
      <sbdh:Receiver><sbdh:Identifier Authority="GS1">0301160000009</sbdh:Identifier></sbdh:Receiver>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <extension>
          <destinationList>
            <destination type="urn:epcglobal:cbv:sdt:location">0301160000009</destination>
          </destinationList>
        </extension>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

        $this->assertSame('0614141000005', $this->senderGln($xml));
    }

    #[Test]
    public function it_reports_no_sender_rather_than_a_number_that_is_not_a_gln(): void
    {
        $this->assertNull($this->senderGln($this->documentWithSbdh('0614141000006')));
        $this->assertNull($this->senderGln($this->documentWithSbdh('WAREHOUSE-7')));
        $this->assertNull($this->senderGln('not xml at all'));
    }

    private function senderGln(string $content): ?string
    {
        $method = new ReflectionMethod(InboundEpcisReceiver::class, 'extractSenderGln');

        /** @var string|null $gln */
        $gln = $method->invoke(app(InboundEpcisReceiver::class), $content);

        return $gln;
    }

    private function documentWithSbdh(string $senderIdentifier): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader">
      <sbdh:Sender><sbdh:Identifier Authority="GS1">{$senderIdentifier}</sbdh:Identifier></sbdh:Sender>
      <sbdh:Receiver><sbdh:Identifier Authority="GS1">0301160000009</sbdh:Identifier></sbdh:Receiver>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody><EventList/></EPCISBody>
</epcis:EPCISDocument>
XML;
    }
}
