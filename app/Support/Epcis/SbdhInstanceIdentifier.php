<?php

namespace App\Support\Epcis;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * SBDH DocumentIdentification/InstanceIdentifier helpers.
 *
 * Prefer {@see uuid()} for authored outbound documents (RFC 4122 under urn:uuid:).
 * {@see fromEventTime()} remains for older callers that stamp a UTC timestamp.
 */
final class SbdhInstanceIdentifier
{
    public static function uuid(): string
    {
        return 'urn:uuid:'.(string) Str::uuid();
    }

    /**
     * Legacy product stamp: urn:uuid:{YmdHis}{v} (UTC event time + milliseconds),
     * optionally suffixed with a discriminator (e.g. session id).
     */
    public static function fromEventTime(CarbonInterface $eventTime, int|string|null $discriminator = null): string
    {
        $utc = $eventTime->copy()->utc();
        $stamp = 'urn:uuid:'.$utc->format('YmdHis').$utc->format('v');

        return $discriminator !== null ? $stamp.'-'.$discriminator : $stamp;
    }
}
