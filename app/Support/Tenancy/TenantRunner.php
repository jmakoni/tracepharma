<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Run tenant callbacks with guaranteed tenancy teardown (even on exception).
 */
final class TenantRunner
{
    public static function run(Tenant $tenant, callable $callback): mixed
    {
        try {
            return $tenant->run($callback);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
