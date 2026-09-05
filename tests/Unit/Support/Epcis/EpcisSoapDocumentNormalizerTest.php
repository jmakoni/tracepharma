<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisSoapDocumentNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisSoapDocumentNormalizerTest extends TestCase
{
    #[Test]
    public function bare_epcis_document_is_unchanged(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);

        $result = (new EpcisSoapDocumentNormalizer)->normalize($xml, requirePure: false);

        $this->assertFalse($result['unwrapped']);
        $this->assertSame($xml, $result['content']);
        $this->assertStringContainsString('EPCISDocument', $result['content']);
        $this->assertStringNotContainsString('Envelope', $result['content']);
    }

    #[Test]
    public function soap_wrapped_epcis_is_unwrapped_by_default(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);

        $result = (new EpcisSoapDocumentNormalizer)->normalize($xml, requirePure: false);

        $this->assertTrue($result['unwrapped']);
        $this->assertStringContainsString('EPCISDocument', $result['content']);
        $this->assertStringNotContainsString('soapenv:Envelope', $result['content']);
        $this->assertStringNotContainsString('soapenv:Body', $result['content']);
    }

    #[Test]
    public function soap_without_epcis_document_is_rejected(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/soap_without_epcis_document.xml'));
        $this->assertNotFalse($xml);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SOAP envelope does not contain a single EPCISDocument');

        (new EpcisSoapDocumentNormalizer)->normalize($xml, requirePure: false);
    }

    #[Test]
    public function require_pure_rejects_soap_with_partner_facing_message(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/soap_wrapped_minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(EpcisSoapDocumentNormalizer::STRICT_REJECT_MESSAGE);

        (new EpcisSoapDocumentNormalizer)->normalize($xml, requirePure: true);
    }

    #[Test]
    public function nested_junk_in_body_with_multiple_epcis_documents_is_rejected(): void
    {
        $inner = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
        $this->assertNotFalse($inner);
        $lines = explode("\n", $inner);
        if (str_starts_with($lines[0] ?? '', '<?xml')) {
            array_shift($lines);
        }
        $bodyDoc = implode("\n", $lines);

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
  <soap:Body>
{$bodyDoc}
{$bodyDoc}
  </soap:Body>
</soap:Envelope>
XML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SOAP envelope does not contain a single EPCISDocument');

        (new EpcisSoapDocumentNormalizer)->normalize($xml, requirePure: false);
    }
}
