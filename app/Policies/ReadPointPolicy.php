<?php

namespace App\Policies;

use App\Models\ReadPoint;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;
use App\Support\Auth\SiteAccess;

class ReadPointPolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReadPoint $readPoint): bool
    {
        if ($readPoint->site_id === null) {
            return true;
        }

        return SiteAccess::canAccessSite($user, (int) $readPoint->site_id);
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, ReadPoint $readPoint): bool
    {
        if (! $this->isMaintainer($user)) {
            return false;
        }

        if ($readPoint->site_id === null) {
            return true;
        }

        return SiteAccess::canAccessSite($user, (int) $readPoint->site_id);
    }

    public function delete(User $user, ReadPoint $readPoint): bool
    {
        return $this->isDeleter($user) && $this->view($user, $readPoint);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
