<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Models\Admin;
use App\Models\Tenant;
use App\Support\Admin\PlatformAudit;
use RuntimeException;

final class SuspendTenant
{
    public function __construct(
        private readonly CascadeTenantPairStatus $cascade,
    ) {}

    public function handle(Tenant $tenant, string $reason, ?Admin $actor = null): Tenant
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A suspension reason is required.');
        }

        if ($tenant->status === 'suspended') {
            throw new RuntimeException("Tenant [{$tenant->name}] is already suspended.");
        }

        $previous = $tenant->status;

        $tenant->forceFill([
            'status' => 'suspended',
            'suspension_reason' => $reason,
            'suspended_at' => now(),
            'suspended_by' => $actor?->getKey(),
        ])->save();

        $this->cascade->handle($tenant->fresh(), $previous);

        PlatformAudit::record(
            'tenant.suspended',
            tenantId: (string) $tenant->getKey(),
            targetType: Tenant::class,
            targetId: (string) $tenant->getKey(),
            payload: ['reason' => $reason, 'previous_status' => $previous],
            actor: $actor,
        );

        return $tenant->refresh();
    }
}
