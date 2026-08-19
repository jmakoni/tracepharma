<?php

namespace App\Services\Integrations;

use App\Models\InboundConnection;
use App\Models\InboundConnectionLog;

class InboundConnectionLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        InboundConnection $connection,
        string $eventType,
        string $status,
        ?string $message = null,
        array $metadata = [],
    ): InboundConnectionLog {
        if ($status === 'failed' && filled($message)) {
            $connection->update(['last_error' => $message]);
        }

        if ($eventType === 'poll' && $status === 'success') {
            $connection->update(['last_polled_at' => now()]);
        }

        if (in_array($eventType, ['receive', 'connectivity_test'], true) && $status === 'success') {
            $connection->update(['last_received_at' => now(), 'last_error' => null]);
        }

        return InboundConnectionLog::query()->create([
            'inbound_connection_id' => $connection->id,
            'event_type' => $eventType,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }
}
