<?php

namespace App\Services\Epcis\Outbound;

final readonly class As2SmimeEnvelopeResult
{
    public function __construct(
        public string $body,
        public string $contentType,
        public bool $smimeApplied,
    ) {}
}
