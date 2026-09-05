<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\User;
use App\Policies\Concerns\AuthorizesAdminActor;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Authenticatable $actor): bool
    {
        if ($actor instanceof Admin) {
            return $this->adminManagesAdmins($actor);
        }

        if ($actor instanceof User) {
            return TenantFeatures::forTenant(tenant())->supportsMasterData()
                && JobRoleAccess::allows(Permissions::NavCompliance, $actor);
        }

        return false;
    }

    public function view(Authenticatable $actor, Activity $activity): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if ($actor instanceof Admin) {
            return true;
        }

        if ($actor instanceof User) {
            if ($actor->can(Permissions::SitesAccessAll)) {
                return true;
            }

            return $activity->causer_type === User::class
                && (int) $activity->causer_id === (int) $actor->getKey();
        }

        return false;
    }

    public function create(Authenticatable $actor): bool
    {
        return false;
    }

    public function update(Authenticatable $actor, Activity $activity): bool
    {
        return false;
    }

    public function delete(Authenticatable $actor, Activity $activity): bool
    {
        return false;
    }
}
