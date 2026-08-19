<?php

namespace App\Actions\Epcis;

use App\Services\Epcis\EpcNormalizer;

/**
 * Materialize epcs column attributes from an SGTIN/SSCC Pure Identity URN.
 */
final class MaterializeEpcKeys
{
    /**
     * @return array<string, mixed>|null
     */
    public function handle(string $epcUri, ?string $ndc11 = null): ?array
    {
        return app(EpcNormalizer::class)->fromUri($epcUri, $ndc11);
    }
}
