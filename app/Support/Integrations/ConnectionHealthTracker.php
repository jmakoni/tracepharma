<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\InboundConnection;
use App\Models\OutboundConnection;

/**
 * Per-connection health rollup: stamps success/failure timestamps and keeps
 * the consecutive-failure streak that connections:health-sweep alerts on.
 */
class ConnectionHealthTracker
{
    public const ALERT_THRESHOLD = 3;

    public const AUTO_PAUSE_THRESHOLD = 10;

    public function recordSuccess(OutboundConnection|InboundConnection $connection): void
    {
        $connection->forceFill([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
        ])->save();
    }

    public function recordFailure(OutboundConnection|InboundConnection $connection, ?string $message = null): void
    {
        $attributes = [
            'last_failure_at' => now(),
            'consecutive_failures' => ((int) $connection->consecutive_failures) + 1,
        ];

        if ($message !== null) {
            $attributes['last_error'] = mb_substr($message, 0, 1000);
        }

        $connection->forceFill($attributes)->save();
    }
}
