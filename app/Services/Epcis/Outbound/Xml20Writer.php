<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Support\Epcis\EpcisSchemaVersion;

/**
 * STUB — not selected by OutboundEpcisWriterResolver.
 *
 * Retags schemaVersion 1.2 → 2.0 without a real EPCIS 2.0 XML event mapping.
 * Prefer JsonLd20Writer for 2.0 outbound until a proper XML 2.0 writer exists.
 */
final class Xml20Writer implements OutboundEpcisDocumentWriter
{
    public function __construct(
        private readonly OutboundEpcisXmlBuilder $builder,
    ) {}

    public function schemaVersion(): string
    {
        return EpcisSchemaVersion::V20;
    }

    public function format(): string
    {
        return EpcisSchemaVersion::FORMAT_XML;
    }

    public function buildDocument(string $eventTime, string $eventsPayload, ?string $correlationId = null): string
    {
        $xml = $this->builder->buildDocument($eventTime, $eventsPayload, $correlationId);

        return str_replace('schemaVersion="1.2"', 'schemaVersion="2.0"', $xml);
    }
}
