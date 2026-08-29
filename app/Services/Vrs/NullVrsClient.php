<?php

namespace App\Services\Vrs;

use App\Exceptions\VrsConfigurationException;
use App\Services\Vrs\Contracts\VrsClient;

final class NullVrsClient implements VrsClient
{
    public function verify(
        string $gtin14,
        string $serial,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
    ): array {
        throw new VrsConfigurationException(
            'VRS is not configured (VRS_DRIVER); verification cannot complete.',
        );
    }
}
