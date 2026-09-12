<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\User;
use App\Policies\Concerns\AuthorizesAdminActor;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Authenticatable $actor): bool
    {
        if ($actor instanceof Admin) {
            return $this->adminManagesAdmins($actor);
        }

        if ($actor instanceof User) {
            return $actor->can(Permissions::UsersManage)
                && JobRoleAccess::allows(Permissions::NavUsers, $actor);
        }

        return false;
    }

    public function view(Authenticatable $actor, Role $role): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Authenticatable $actor): bool
    {
        return false;
    }

    public function update(Authenticatable $actor, Role $role): bool
    {
        return $this->viewAny($actor);
    }

    public function delete(Authenticatable $actor, Role $role): bool
    {
        return false;
    }
}
