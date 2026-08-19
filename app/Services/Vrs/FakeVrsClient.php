<?php

namespace App\Services\Vrs;

use App\Services\Vrs\Contracts\VrsClient;

final class FakeVrsClient implements VrsClient
{
    public function verify(
        string $gtin14,
        string $serial,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
    ): array {
        $base = [
            'gtin14' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'expiry_yymmdd' => $expiryYymmdd,
        ];

        if (str_starts_with(strtoupper($serial), 'FAIL')) {
            return [
                ...$base,
                'status' => 'failed',
                'message' => 'GTIN and serial do not match manufacturer records.',
            ];
        }

        if (str_starts_with(strtoupper($serial), 'NETWORK')) {
            return [
                ...$base,
                'status' => 'suspect',
                'message' => 'Product not in the verification network.',
            ];
        }

        return [
            ...$base,
            'status' => 'verified',
            'message' => 'Product verified (fake VRS).',
        ];
    }
}
