<?php

namespace App\Policies;

use App\Models\LocationDevice;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;
use App\Support\Auth\SiteAccess;

class LocationDevicePolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LocationDevice $locationDevice): bool
    {
        if ($locationDevice->site_id === null) {
            return true;
        }

        return SiteAccess::canAccessSite($user, (int) $locationDevice->site_id);
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, LocationDevice $locationDevice): bool
    {
        if (! $this->isMaintainer($user)) {
            return false;
        }

        if ($locationDevice->site_id === null) {
            return true;
        }

        return SiteAccess::canAccessSite($user, (int) $locationDevice->site_id);
    }

    public function delete(User $user, LocationDevice $locationDevice): bool
    {
        return $this->isDeleter($user) && $this->view($user, $locationDevice);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
