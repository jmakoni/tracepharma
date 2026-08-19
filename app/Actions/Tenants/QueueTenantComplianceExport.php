<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Jobs\Tenants\ExportTenantComplianceArchive;
use App\Models\Admin;
use App\Models\Tenant;
use App\Support\Auth\Permissions;

final class QueueTenantComplianceExport
{
    public function execute(Admin $admin, Tenant $tenant): void
    {
        if (! $admin->can(Permissions::TenantsManage)) {
            abort(403);
        }

        ExportTenantComplianceArchive::dispatch($tenant, (int) $admin->getKey());
    }
}
