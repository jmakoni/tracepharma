<?php

namespace App\Services\Dscsa\ComplianceReport;

final readonly class SerialRow
{
    public function __construct(
        public string $gtin,
        public string $serialNumber,
        public string $lot,
        public string $expirationDate,
    ) {}
}
