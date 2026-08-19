<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

class TenantNotificationSettings
{
    /**
     * @return array{notify_email: bool, notify_database: bool, channels: list<string>}
     */
    public static function forCurrentTenant(): array
    {
        return self::forTenant(tenancy()->initialized ? tenant() : null);
    }

    /**
     * @return array{notify_email: bool, notify_database: bool, channels: list<string>}
     */
    public static function forTenant(?Tenant $tenant): array
    {
        $notifyEmail = true;
        $notifyDatabase = Schema::hasTable('notifications');

        $channels = [];

        if ($notifyEmail) {
            $channels[] = 'mail';
        }

        if ($notifyDatabase) {
            $channels[] = 'database';
        }

        return [
            'notify_email' => $notifyEmail,
            'notify_database' => $notifyDatabase,
            'channels' => $channels,
        ];
    }
}
