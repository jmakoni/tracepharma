<?php

namespace App\Services\Vrs;

use App\Services\Vrs\Contracts\VrsClient;

final class NullVrsClient implements VrsClient
{
    public function verify(
        string $gtin14,
        string $serial,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
    ): array {
        return [
            'status' => 'deferred',
            'gtin14' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'expiry_yymmdd' => $expiryYymmdd,
            'message' => 'VRS verification is deferred.',
        ];
    }
}
