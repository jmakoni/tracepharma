<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use RuntimeException;

trait ResolvesTenantConnections
{
    protected function resolveTenantOrFail(string $tenantId): Tenant
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException("Tenant [{$tenantId}] not found.");
        }

        return $tenant;
    }

    /**
     * @return array{0: InboundConnection|OutboundConnection, 1: string}
     */
    protected function resolveConnectionOrFail(Tenant $tenant, int|string $connectionId, string $direction): array
    {
        if (! in_array($direction, ['inbound', 'outbound'], true)) {
            throw new RuntimeException("Direction must be 'inbound' or 'outbound', got [{$direction}].");
        }

        $connection = TenantRunner::run($tenant, static function () use ($connectionId, $direction) {
            return $direction === 'inbound'
                ? InboundConnection::query()->find($connectionId)
                : OutboundConnection::query()->find($connectionId);
        });

        if ($connection === null) {
            throw new RuntimeException("No {$direction} connection [{$connectionId}] on tenant [{$tenant->getKey()}].");
        }

        return [$connection, $direction];
    }
}
