<?php

namespace App\Services\Epcis;

use App\Services\Epcis\Contracts\EpcisDocumentParser;
use App\Support\Epcis\EpcisXmlReader;

final class EpcisXmlParser implements EpcisDocumentParser
{
    public function __construct(
        private readonly EpcisXmlReader $reader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(string $absolutePath): array
    {
        return $this->reader->parse($absolutePath);
    }

    /**
     * @param  callable(array<string, mixed>): void  $onEvent
     * @return array<string, mixed>
     */
    public function parseHeaderAndStream(string $absolutePath, callable $onEvent): array
    {
        return $this->reader->parseHeaderAndStream($absolutePath, $onEvent);
    }
}
