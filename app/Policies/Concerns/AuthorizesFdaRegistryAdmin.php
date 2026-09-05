<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Admin;

trait AuthorizesFdaRegistryAdmin
{
    use AuthorizesAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return true;
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function delete(Admin $admin, mixed $model): bool
    {
        return false;
    }
}
