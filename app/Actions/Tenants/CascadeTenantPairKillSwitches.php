<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Support\TenantSettings;

final class CascadeTenantPairKillSwitches
{
    public function __construct(
        private readonly DeleteTenantPair $deletePair,
    ) {}

    /**
     * @param  array<string, bool>  $switches
     */
    public function handle(Tenant $tenant, array $switches): void
    {
        if ($switches === []) {
            return;
        }

        $sibling = $this->deletePair->sibling($tenant);

        if (! $sibling instanceof Tenant) {
            return;
        }

        Tenant::withoutEvents(function () use ($sibling, $switches): void {
            TenantSettings::forTenant($sibling)->setKillSwitches($switches);
            $sibling->save();
        });
    }
}
