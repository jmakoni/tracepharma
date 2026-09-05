<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Bind path `{tenantId}` webhooks to Host-initialized tenancy when present.
 *
 * When Host tenancy is active (`tenant()` is set via InitializeTenancyForTenantHosts),
 * the path tenant must match; otherwise abort 404.
 *
 * When no Host tenant is active (central domains / EPCIS hub hosts skip Host tenancy),
 * path-only tenancy is intentional for partner webhook URLs — allow the path tenant.
 */
final class AssertWebhookTenantMatchesHost
{
    public static function assert(string $tenantId): void
    {
        $current = tenant();

        // Central/hub path-only: no Host tenant — allow path tenant.
        if ($current === null) {
            return;
        }

        if ((string) $current->getKey() !== $tenantId) {
            abort(404);
        }
    }
}
