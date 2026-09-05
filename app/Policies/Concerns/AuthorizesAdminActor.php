<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Admin;
use App\Support\Auth\Permissions;

trait AuthorizesAdminActor
{
    protected function adminCan(Admin $admin, string $permission): bool
    {
        return $admin->can($permission);
    }

    protected function isAdmin(mixed $actor): bool
    {
        return $actor instanceof Admin;
    }

    protected function adminManagesAdmins(Admin $admin): bool
    {
        return $this->adminCan($admin, Permissions::AdminsManage);
    }

    protected function adminManagesTenants(Admin $admin): bool
    {
        return $this->adminCan($admin, Permissions::TenantsManage);
    }

    protected function adminManagesCatalog(Admin $admin): bool
    {
        return $this->adminCan($admin, Permissions::CatalogManage);
    }
}
