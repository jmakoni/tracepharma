<?php

declare(strict_types=1);

namespace App\Support\EpcisHub;

use App\Models\EpcisHubRoute;
use App\Models\Tenant;
use RuntimeException;

/**
 * A receiver GLN may only route to one tenant per provider within an
 * environment — otherwise inbound documents would be ambiguous. Shared by the
 * admin claim action and the connection auto-registration path.
 */
class HubRouteConflictGuard
{
    public function assertExclusive(Tenant $tenant, string $provider, string $gln): void
    {
        $environment = (string) $tenant->inbound_environment;

        $otherTenantIds = EpcisHubRoute::query()
            ->where('provider', $provider)
            ->where('gln', $gln)
            ->where('is_active', true)
            ->where('tenant_id', '!=', $tenant->getKey())
            ->pluck('tenant_id')
            ->unique()
            ->all();

        if ($otherTenantIds === []) {
            return;
        }

        $conflict = Tenant::query()
            ->whereIn('id', $otherTenantIds)
            ->where('inbound_environment', $environment)
            ->first();

        if ($conflict !== null) {
            throw new RuntimeException(
                "GLN [{$gln}] is already claimed for provider [{$provider}] by tenant [{$conflict->name}] in environment [{$environment}].",
            );
        }
    }
}
