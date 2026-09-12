<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Tenant;
use App\Policies\Concerns\AuthorizesAdminActor;

class TenantPolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $this->adminManagesTenants($admin);
    }

    public function view(Admin $admin, Tenant $tenant): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, Tenant $tenant): bool
    {
        return $this->viewAny($admin);
    }

    public function delete(Admin $admin, Tenant $tenant): bool
    {
        return $this->viewAny($admin);
    }
}
