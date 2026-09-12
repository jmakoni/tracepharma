<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaEstablishment;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaEstablishmentPolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaEstablishment $establishment): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->adminManagesCatalog($admin);
    }

    public function update(Admin $admin, FdaEstablishment $establishment): bool
    {
        return $this->adminManagesCatalog($admin);
    }
}
