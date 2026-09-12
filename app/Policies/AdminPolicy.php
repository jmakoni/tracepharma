<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Policies\Concerns\AuthorizesAdminActor;

class AdminPolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $this->adminManagesAdmins($admin);
    }

    public function view(Admin $admin, Admin $model): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, Admin $model): bool
    {
        return $this->viewAny($admin);
    }

    public function delete(Admin $admin, Admin $model): bool
    {
        if ($admin->is($model)) {
            return false;
        }

        return $this->viewAny($admin);
    }
}
