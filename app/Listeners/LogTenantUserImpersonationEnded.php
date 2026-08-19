<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Admin;
use App\Support\Admin\AdminActivityLogger;
use App\Support\Admin\TenantImpersonation;
use Illuminate\Auth\Events\Logout;

final class LogTenantUserImpersonationEnded
{
    public function __construct(
        private AdminActivityLogger $activityLogger,
    ) {}

    public function handle(Logout $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $payload = TenantImpersonation::forget();
        if ($payload === null) {
            return;
        }

        $adminId = data_get($payload, 'admin_id');
        $central = (string) config('tenancy.database.central_connection', config('database.default'));
        $admin = is_numeric($adminId)
            ? Admin::on($central)->find((int) $adminId)
            : null;

        $properties = [
            'tenant_id' => data_get($payload, 'tenant_id'),
            'target_user_id' => data_get($payload, 'target_user_id'),
            'reason' => data_get($payload, 'reason'),
            'started_at' => data_get($payload, 'started_at'),
            'ended_at' => now()->toIso8601String(),
            'ip' => request()->ip(),
        ];

        if ($admin instanceof Admin) {
            $this->activityLogger->log('tenant_user_impersonation_ended', $admin, $properties);

            return;
        }

        $this->activityLogger->logWithoutCauser('tenant_user_impersonation_ended', $properties);
    }
}
