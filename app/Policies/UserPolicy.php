<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::UsersManage)
            && JobRoleAccess::allows(Permissions::NavUsers, $user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $this->update($user, $model);
    }
}
