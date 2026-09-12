<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaOrganizationPolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaOrganization $organization): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, FdaOrganization $organization): bool
    {
        return $this->adminManagesCatalog($admin);
    }
}
