<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;

final class TenantAccess
{
    public static function isActive(?Tenant $tenant = null): bool
    {
        $tenant = self::resolve($tenant);

        if ($tenant === null) {
            return false;
        }

        return self::freshStatus($tenant) === 'active';
    }

    public static function assertActive(?Tenant $tenant = null): void
    {
        if (self::isActive($tenant)) {
            return;
        }

        $message = 'This organization account is suspended.';

        abort(403, $message);
    }

    private static function resolve(?Tenant $tenant): ?Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $current = tenant();

        return $current instanceof Tenant ? $current : null;
    }

    private static function freshStatus(Tenant $tenant): string
    {
        $fresh = Tenant::query()->find($tenant->getKey());
        $status = $fresh?->status;

        return is_string($status) ? $status : '';
    }
}
