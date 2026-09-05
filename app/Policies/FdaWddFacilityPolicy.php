<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaWddFacility;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaWddFacilityPolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaWddFacility $facility): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->adminManagesCatalog($admin);
    }

    public function update(Admin $admin, FdaWddFacility $facility): bool
    {
        return $this->adminManagesCatalog($admin);
    }
}
