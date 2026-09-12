<?php

namespace App\Support\Vrs;

use App\Exceptions\VrsConfigurationException;
use App\Services\Vrs\HttpVrsClient;

final class AssertVrsDriverReady
{
    public function handle(): void
    {
        $driver = config('vrs.driver');

        if ($driver === null || $driver === '' || $driver === 'null') {
            throw new VrsConfigurationException(
                'VRS is not configured (VRS_DRIVER); verification cannot complete.',
            );
        }

        if ($driver === 'http') {
            HttpVrsClient::assertConfigured();
        }
    }
}
