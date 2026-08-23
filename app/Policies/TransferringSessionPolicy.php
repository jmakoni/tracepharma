<?php

namespace App\Policies;

use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;

class TransferringSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user);
    }

    public function view(User $user, TransferringSession $session): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user)
            && $this->canAccessEitherSite($user, $session);
    }

    public function create(User $user): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user);
    }

    public function update(User $user, TransferringSession $session): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user)
            && $this->canAccessEitherSite($user, $session);
    }

    public function delete(User $user, TransferringSession $session): bool
    {
        if (! JobRoleAccess::allows(Permissions::NavShip, $user)) {
            return false;
        }

        $fromSiteId = $session->from_site_id;
        if ($fromSiteId === null) {
            return false;
        }

        return SiteAccess::canAccessSite($user, (int) $fromSiteId);
    }

    private function canAccessEitherSite(User $user, TransferringSession $session): bool
    {
        $fromSiteId = $session->from_site_id;
        $toSiteId = $session->to_site_id;

        if ($fromSiteId !== null && SiteAccess::canAccessSite($user, (int) $fromSiteId)) {
            return true;
        }

        if ($toSiteId !== null && SiteAccess::canAccessSite($user, (int) $toSiteId)) {
            return true;
        }

        return false;
    }
}
