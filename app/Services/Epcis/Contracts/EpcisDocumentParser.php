<?php

namespace App\Services\Epcis\Contracts;

interface EpcisDocumentParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $absolutePath): array;
}
