<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Models\Admin;
use App\Models\Tenant;
use App\Support\Admin\PlatformAudit;
use RuntimeException;

final class ActivateTenant
{
    public function __construct(
        private readonly CascadeTenantPairStatus $cascade,
    ) {}

    public function handle(Tenant $tenant, ?Admin $actor = null): Tenant
    {
        if ($tenant->status === 'active') {
            throw new RuntimeException("Tenant [{$tenant->name}] is already active.");
        }

        $previous = $tenant->status;

        $tenant->forceFill([
            'status' => 'active',
            'suspension_reason' => null,
            'suspended_at' => null,
            'suspended_by' => null,
        ])->save();

        $this->cascade->handle($tenant->fresh(), $previous);

        PlatformAudit::record(
            'tenant.activated',
            tenantId: (string) $tenant->getKey(),
            targetType: Tenant::class,
            targetId: (string) $tenant->getKey(),
            payload: ['previous_status' => $previous],
            actor: $actor,
        );

        return $tenant->refresh();
    }
}
