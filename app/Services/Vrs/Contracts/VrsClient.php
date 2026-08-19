<?php

namespace App\Services\Vrs\Contracts;

interface VrsClient
{
    /**
     * @param  string|null  $expiryYymmdd  GS1 AI 17 expiration date, when the scan carried one.
     * @return array{status: string, gtin14: string, serial: string, lot: string|null, expiry_yymmdd: string|null, message: string}
     */
    public function verify(
        string $gtin14,
        string $serial,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
    ): array;
}
