<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\DemoRequest;
use App\Policies\Concerns\AuthorizesAdminActor;

class DemoRequestPolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $this->adminManagesTenants($admin);
    }

    public function view(Admin $admin, DemoRequest $request): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, DemoRequest $request): bool
    {
        return false;
    }

    public function delete(Admin $admin, DemoRequest $request): bool
    {
        return false;
    }
}
