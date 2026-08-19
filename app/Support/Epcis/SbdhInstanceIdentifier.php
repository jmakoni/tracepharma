<?php

namespace App\Support\Epcis;

use Carbon\CarbonInterface;

/**
 * Product convention for SBDH DocumentIdentification/InstanceIdentifier:
 * urn:uuid:{YmdHis}{v} (UTC event time + milliseconds, 17 digits), optionally
 * suffixed with a discriminator (e.g. session id) so two documents authored
 * from the same event time cannot collide on the document_uuid unique index.
 */
final class SbdhInstanceIdentifier
{
    public static function fromEventTime(CarbonInterface $eventTime, int|string|null $discriminator = null): string
    {
        $utc = $eventTime->copy()->utc();
        $stamp = 'urn:uuid:'.$utc->format('YmdHis').$utc->format('v');

        return $discriminator !== null ? $stamp.'-'.$discriminator : $stamp;
    }
}
