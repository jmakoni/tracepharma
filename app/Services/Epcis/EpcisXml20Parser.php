<?php

declare(strict_types=1);

namespace App\Services\Epcis;

use App\Services\Epcis\Contracts\EpcisDocumentParser;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\EpcisXmlReader;
use InvalidArgumentException;

/**
 * EPCIS 2.0 XML ingest adapter — reuses the XML reader for DSCSA Object/Aggregation events.
 */
final class EpcisXml20Parser implements EpcisDocumentParser
{
    public function __construct(
        private readonly EpcisXmlReader $reader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(string $absolutePath): array
    {
        if (! EpcisSchemaVersion::accepts20()) {
            throw new InvalidArgumentException(
                'EPCIS 2.0 XML parsing is disabled (TRACEPHARMA_EPCIS_ACCEPT_20).',
            );
        }

        $parsed = $this->reader->parse($absolutePath);
        $parsed['schema_version'] = EpcisSchemaVersion::V20;

        return $parsed;
    }

    /**
     * @param  callable(array<string, mixed>): void  $onEvent
     * @return array<string, mixed>
     */
    public function parseHeaderAndStream(string $absolutePath, callable $onEvent): array
    {
        if (! EpcisSchemaVersion::accepts20()) {
            throw new InvalidArgumentException(
                'EPCIS 2.0 XML parsing is disabled (TRACEPHARMA_EPCIS_ACCEPT_20).',
            );
        }

        $header = $this->reader->parseHeaderAndStream($absolutePath, $onEvent);
        $header['schema_version'] = EpcisSchemaVersion::V20;

        return $header;
    }
}
