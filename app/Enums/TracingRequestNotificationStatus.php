<?php

namespace App\Enums;

enum TracingRequestNotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Acknowledged = 'acknowledged';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Acknowledged => 'Acknowledged',
        };
    }
}
