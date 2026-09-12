<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaProduct;
use App\Models\User;
use App\Policies\Concerns\AuthorizesAdminActor;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use Illuminate\Contracts\Auth\Authenticatable;

class FdaProductPolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Authenticatable $actor): bool
    {
        if ($actor instanceof Admin) {
            return true;
        }

        if ($actor instanceof User) {
            return TenantFeatures::forTenant(tenant())->supportsMasterData()
                && JobRoleAccess::allows(Permissions::NavMasterData, $actor);
        }

        return false;
    }

    public function view(Authenticatable $actor, FdaProduct $product): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Authenticatable $actor): bool
    {
        return false;
    }

    public function update(Authenticatable $actor, FdaProduct $product): bool
    {
        if ($actor instanceof Admin) {
            return $this->adminManagesCatalog($actor);
        }

        return false;
    }

    public function delete(Authenticatable $actor, FdaProduct $product): bool
    {
        return false;
    }
}
