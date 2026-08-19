<?php

declare(strict_types=1);

namespace Tests\Unit\Epcis;

use App\Support\Epcis\SbdhHeaderExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SbdhHeaderExtractorTest extends TestCase
{
    #[Test]
    public function extracts_sender_and_receiver_gln_from_sbdh(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<StandardBusinessDocumentHeader xmlns="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader">
  <Sender><Identifier Authority="GLN">0360002914150</Identifier></Sender>
  <Receiver><Identifier Authority="GLN">0123456789012</Identifier></Receiver>
</StandardBusinessDocumentHeader>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"><EPCISBody/></epcis:EPCISDocument>
XML;

        $parties = app(SbdhHeaderExtractor::class)->extract($xml);

        $this->assertSame('0360002914150', $parties['sender_gln']);
        $this->assertSame('0123456789012', $parties['receiver_gln']);
    }

    #[Test]
    public function wraps_multi_root_xml_after_stripping_declaration(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<StandardBusinessDocumentHeader xmlns="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader">
  <Sender><Identifier Authority="GLN">0360002914150</Identifier></Sender>
  <Receiver><Identifier Authority="GLN">0123456789012</Identifier></Receiver>
</StandardBusinessDocumentHeader>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"><EPCISBody/></epcis:EPCISDocument>
XML;

        $parties = app(SbdhHeaderExtractor::class)->extract($xml);

        $this->assertSame('0360002914150', $parties['sender_gln']);
        $this->assertSame('0123456789012', $parties['receiver_gln']);
    }

    #[Test]
    public function returns_empty_parties_for_non_xml(): void
    {
        $parties = app(SbdhHeaderExtractor::class)->extract('not xml');

        $this->assertNull($parties['sender_gln']);
        $this->assertNull($parties['receiver_gln']);
    }
}
