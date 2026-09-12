<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Outbound;

use App\Domain\Epcis\EpcisEventFactory;
use App\Domain\Epcis\Enums\EpcisAction;
use App\Models\OutboundConnection;
use App\Services\Epcis\Outbound\JsonLd20Writer;
use App\Services\Epcis\Outbound\OutboundEpcisWriterResolver;
use App\Services\Epcis\Outbound\Xml12Writer;
use App\Support\Epcis\EpcisSchemaVersion;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundEpcisWriterResolverTest extends TestCase
{
    #[Test]
    public function defaults_to_xml_1_2(): void
    {
        config([
            'tracepharma.epcis.accept_20' => false,
            'tracepharma.epcis.default_outbound_version' => '1.2',
        ]);

        $writer = app(OutboundEpcisWriterResolver::class)->forConnection(null);

        $this->assertInstanceOf(Xml12Writer::class, $writer);
        $this->assertSame(EpcisSchemaVersion::V12, $writer->schemaVersion());
    }

    #[Test]
    public function platform_default_2_0_uses_json_ld_when_accept_20_on(): void
    {
        config([
            'tracepharma.epcis.accept_20' => true,
            'tracepharma.epcis.default_outbound_version' => '2.0',
        ]);

        $writer = app(OutboundEpcisWriterResolver::class)->forConnection(null);

        $this->assertInstanceOf(JsonLd20Writer::class, $writer);
    }

    #[Test]
    public function pinned_1_2_connection_stays_on_xml_1_2_when_platform_default_is_2_0(): void
    {
        config([
            'tracepharma.epcis.accept_20' => true,
            'tracepharma.epcis.default_outbound_version' => '2.0',
        ]);

        $connection = new OutboundConnection([
            'settings' => ['epcis_document_version' => '1.2'],
        ]);

        $writer = app(OutboundEpcisWriterResolver::class)->forConnection($connection);

        $this->assertInstanceOf(Xml12Writer::class, $writer);
    }

    #[Test]
    public function partner_setting_selects_json_2_0_when_flag_on(): void
    {
        config(['tracepharma.epcis.accept_20' => true]);

        $connection = new OutboundConnection([
            'settings' => ['epcis_document_version' => '2.0'],
        ]);

        $writer = app(OutboundEpcisWriterResolver::class)->forConnection($connection);

        $this->assertInstanceOf(JsonLd20Writer::class, $writer);
    }

    #[Test]
    public function partner_setting_xml_2_0_falls_back_to_json_ld_not_stub_writer(): void
    {
        config(['tracepharma.epcis.accept_20' => true]);

        $connection = new OutboundConnection([
            'settings' => [
                'epcis_document_version' => '2.0',
                'epcis_document_format' => 'xml',
            ],
        ]);

        $writer = app(OutboundEpcisWriterResolver::class)->forConnection($connection);

        // Xml20Writer is a stub; resolver must not select it.
        $this->assertInstanceOf(JsonLd20Writer::class, $writer);
        $this->assertSame('2.0', $writer->schemaVersion());
        $this->assertSame(EpcisSchemaVersion::FORMAT_JSON, $writer->format());
    }

    #[Test]
    public function partner_2_0_falls_back_to_1_2_when_flag_off(): void
    {
        config(['tracepharma.epcis.accept_20' => false]);

        $connection = new OutboundConnection([
            'settings' => ['epcis_document_version' => '2.0'],
        ]);

        $writer = app(OutboundEpcisWriterResolver::class)->forConnection($connection);

        $this->assertInstanceOf(Xml12Writer::class, $writer);
    }

    #[Test]
    public function json_ld_writer_emits_schema_version_2_0_from_domain_dto(): void
    {
        $factory = app(EpcisEventFactory::class);
        $event = $factory->objectEvent(
            epcList: ['urn:epc:id:sgtin:030116.0200116.10000082001560'],
            action: EpcisAction::Add,
            bizStep: 'commissioning',
            disposition: 'active',
            eventTimeUtc: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            readPoint: 'urn:epc:id:sgln:0614141.00000.0',
        );

        $json = app(JsonLd20Writer::class)->buildFromDomainEvents([$event]);
        $decoded = json_decode($json, true);

        $this->assertSame('EPCISDocument', $decoded['type']);
        $this->assertSame('2.0', $decoded['schemaVersion']);
        $this->assertSame('ObjectEvent', $decoded['epcisBody']['eventList'][0]['type']);
        $this->assertSame('urn:epcglobal:cbv:bizstep:commissioning', $decoded['epcisBody']['eventList'][0]['bizStep']);
    }
}
