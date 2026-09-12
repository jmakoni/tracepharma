<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TenantProfile;
use App\Models\L3\SerializationLot;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;

class SerializationLotPolicy
{
    public function viewAny(User $user): bool
    {
        if (tenant()?->profile !== TenantProfile::Manufacturer) {
            return false;
        }

        return JobRoleAccess::allows(Permissions::NavShip, $user)
            || JobRoleAccess::allows(Permissions::NavIntegrations, $user)
            || JobRoleAccess::allows(Permissions::NavCompliance, $user);
    }

    public function view(User $user, SerializationLot $lot): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return true;
        }

        return $lot->site_id !== null
            && SiteAccess::canAccessSite($user, (int) $lot->site_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SerializationLot $lot): bool
    {
        return false;
    }

    public function delete(User $user, SerializationLot $lot): bool
    {
        return false;
    }
}
