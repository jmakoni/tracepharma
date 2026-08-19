<?php

namespace App\Actions\Vrs;

use App\Jobs\Vrs\RunProductVerificationJob;
use App\Models\Epcis\Epc;
use App\Support\Gs1\ElementString;
use App\Support\TenantFeatures;

final class QueueProductVerificationFromReceive
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function handle(array $result, string $scannedRaw, ?int $actorId = null): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsVrs()) {
            return;
        }

        $driver = config('vrs.driver');

        if ($driver === null || $driver === '' || $driver === 'null') {
            return;
        }

        /** @var Epc|null $epc */
        $epc = $result['epc'] ?? null;

        if ($epc === null || $epc->epc_type === 'sscc') {
            return;
        }

        $gtin14 = $epc->gtin14;
        $serial = $epc->serial_number;

        if (! filled($gtin14) || ! filled($serial)) {
            return;
        }

        $scan = ElementString::sgtinIdentity($scannedRaw) !== null
            ? $scannedRaw
            : '(01)'.$gtin14.'(21)'.$serial;

        RunProductVerificationJob::dispatch(
            (string) tenant()->getTenantKey(),
            $scan,
            $actorId,
        );
    }
}
