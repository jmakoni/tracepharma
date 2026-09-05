<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Outbound;

use App\Services\Epcis\Outbound\OutboundEpcisXmlBuilder;
use App\Support\Epcis\EpcisTempFile;
use App\Support\Epcis\Validation\EpcisXsdValidator;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OutboundEpcisXmlBuilderTest extends TestCase
{
    private const SENDER_GLN = '0301160000010';

    private const RECEIVER_GLN = '0301160000009';

    #[Test]
    public function build_document_without_correlation_id_has_no_epcis_header(): void
    {
        $builder = new OutboundEpcisXmlBuilder;

        $xml = $builder->buildDocument(
            '2026-08-27T13:43:50Z',
            $this->minimalObjectEventXml(),
            null,
        );

        $this->assertStringNotContainsString('<EPCISHeader>', $xml);
        $this->assertStringNotContainsString('<sbdh:StandardBusinessDocumentHeader>', $xml);
    }

    #[Test]
    public function build_document_with_correlation_id_passes_epcis_1_2_xsd(): void
    {
        $builder = new OutboundEpcisXmlBuilder;

        $xml = $builder->buildDocument(
            '2026-08-27T13:43:50Z',
            $this->minimalObjectEventXml(),
            'outbound-correlation-test-1',
            self::SENDER_GLN,
            self::RECEIVER_GLN,
        );

        $this->assertStringContainsString('<EPCISHeader>', $xml);
        $this->assertStringContainsString('<sbdh:StandardBusinessDocumentHeader>', $xml);
        $this->assertStringContainsString(
            '<sbdh:Identifier Authority="GLN">'.self::SENDER_GLN.'</sbdh:Identifier>',
            $xml,
        );
        $this->assertStringContainsString(
            '<sbdh:Identifier Authority="GLN">'.self::RECEIVER_GLN.'</sbdh:Identifier>',
            $xml,
        );
        $this->assertStringNotContainsString('0000000000000', $xml);
        $this->assertLessThan(
            strpos($xml, '<tracepharma:OutboundCorrelation>'),
            strpos($xml, '<sbdh:StandardBusinessDocumentHeader>'),
        );
        $this->assertStringContainsString(
            '<tracepharma:OutboundCorrelation>outbound-correlation-test-1</tracepharma:OutboundCorrelation>',
            $xml,
        );

        $path = EpcisTempFile::write($xml, 'outbound-builder-xsd.xml', 'outbound_builder_xsd_');

        try {
            $this->assertSame([], app(EpcisXsdValidator::class)->validateFile($path));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function build_document_with_correlation_id_requires_real_sender_and_receiver_glns(): void
    {
        $builder = new OutboundEpcisXmlBuilder;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sender');

        $builder->buildDocument(
            '2026-08-27T13:43:50Z',
            $this->minimalObjectEventXml(),
            'outbound-correlation-missing-gln',
        );
    }

    #[Test]
    public function build_document_rejects_placeholder_gln(): void
    {
        $builder = new OutboundEpcisXmlBuilder;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('placeholder');

        $builder->buildDocument(
            '2026-08-27T13:43:50Z',
            $this->minimalObjectEventXml(),
            'outbound-correlation-placeholder',
            '0000000000000',
            self::RECEIVER_GLN,
        );
    }

    private function minimalObjectEventXml(): string
    {
        return <<<'XML'
      <ObjectEvent>
        <eventTime>2026-08-27T13:43:50.000Z</eventTime>
        <recordTime>2026-08-27T13:43:51.000Z</recordTime>
        <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sscc:030116.01001227967</epc>
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
      </ObjectEvent>
XML;
    }
}
