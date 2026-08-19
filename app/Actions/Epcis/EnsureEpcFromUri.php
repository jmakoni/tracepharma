<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\Epc;
use InvalidArgumentException;

/**
 * Find or create an Epc row from a Pure Identity URN.
 */
final class EnsureEpcFromUri
{
    public function handle(string $uri): Epc
    {
        $attrs = app(MaterializeEpcKeys::class)->handle($uri);

        if ($attrs === null) {
            throw new InvalidArgumentException("Unsupported EPC URI: {$uri}");
        }

        return Epc::query()->updateOrCreate(
            ['epc_uri' => $attrs['epc_uri']],
            $attrs,
        );
    }
}
