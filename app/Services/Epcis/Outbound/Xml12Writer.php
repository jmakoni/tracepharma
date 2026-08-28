<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Support\Epcis\EpcisSchemaVersion;

/**
 * Default outbound writer — preserves existing EPCIS 1.2 XML behavior.
 */
final class Xml12Writer implements OutboundEpcisDocumentWriter
{
    public function __construct(
        private readonly OutboundEpcisXmlBuilder $builder,
    ) {}

    public function schemaVersion(): string
    {
        return EpcisSchemaVersion::V12;
    }

    public function format(): string
    {
        return EpcisSchemaVersion::FORMAT_XML;
    }

    public function buildDocument(string $eventTime, string $eventsPayload, ?string $correlationId = null): string
    {
        return $this->builder->buildDocument($eventTime, $eventsPayload, $correlationId);
    }
}
