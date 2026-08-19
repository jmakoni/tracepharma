<?php

declare(strict_types=1);

namespace App\Actions\Tracing;

use App\Enums\TracingRequestNotificationStatus;
use App\Models\TracingRequestNotification;

class AcknowledgeRecallBroadcast
{
    public function execute(TracingRequestNotification $notification): TracingRequestNotification
    {
        $notification->loadMissing('tradingPartner');

        if ($notification->ack_share_uuid === null
            || $notification->tradingPartner?->is_active !== true
        ) {
            return $notification;
        }

        if ($notification->status === TracingRequestNotificationStatus::Acknowledged) {
            return $notification;
        }

        $notification->update([
            'status' => TracingRequestNotificationStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);

        return $notification->refresh();
    }
}
