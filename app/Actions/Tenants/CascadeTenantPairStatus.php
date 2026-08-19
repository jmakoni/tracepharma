<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

final class CascadeTenantPairStatus
{
    public function __construct(
        private readonly DeleteTenantPair $deletePair,
    ) {}

    public function handle(Tenant $tenant, ?string $previousStatus = null): void
    {
        $sibling = $this->deletePair->sibling($tenant);

        if ($sibling instanceof Tenant && $sibling->status !== $tenant->status) {
            Tenant::withoutEvents(function () use ($sibling, $tenant): void {
                $sibling->forceFill(['status' => $tenant->status])->save();
            });
        }

        $this->logStatusChange($tenant, $previousStatus, $sibling);
    }

    private function logStatusChange(Tenant $tenant, ?string $previousStatus, ?Tenant $sibling): void
    {
        if ($previousStatus === null || $previousStatus === $tenant->status) {
            return;
        }

        $logger = activity()
            ->useLog('tenant')
            ->withProperties([
                'tenant_id' => $tenant->getKey(),
                'previous_status' => $previousStatus,
                'new_status' => $tenant->status,
                'sibling_id' => $sibling?->getKey(),
                'sibling_status' => $sibling?->fresh()?->status,
            ]);

        $admin = Auth::guard('admin')->user();
        if ($admin !== null) {
            $logger->causedBy($admin);
        }

        $logger->log('Tenant status changed.');
    }
}
