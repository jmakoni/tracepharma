<?php

namespace App\Policies;

use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;

class ReceivingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return JobRoleAccess::allows(Permissions::NavReceive, $user);
    }

    public function view(User $user, ReceivingSession $session): bool
    {
        return JobRoleAccess::allows(Permissions::NavReceive, $user)
            && $this->canAccessSessionSite($user, $session);
    }

    public function create(User $user): bool
    {
        return JobRoleAccess::allows(Permissions::NavReceive, $user);
    }

    public function update(User $user, ReceivingSession $session): bool
    {
        return JobRoleAccess::allows(Permissions::NavReceive, $user)
            && $this->canAccessSessionSite($user, $session);
    }

    public function delete(User $user, ReceivingSession $session): bool
    {
        return $this->update($user, $session);
    }

    private function canAccessSessionSite(User $user, ReceivingSession $session): bool
    {
        $siteId = $session->site_id;

        if ($siteId === null) {
            return $user->can(Permissions::SitesAccessAll);
        }

        return SiteAccess::canAccessSite($user, (int) $siteId);
    }
}
