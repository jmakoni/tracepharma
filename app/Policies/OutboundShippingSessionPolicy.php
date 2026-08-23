<?php

namespace App\Policies;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;

class OutboundShippingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user);
    }

    public function view(User $user, OutboundShippingSession $session): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user)
            && SiteAccess::canAccessSite($user, (int) $session->site_id);
    }

    public function create(User $user): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user);
    }

    public function update(User $user, OutboundShippingSession $session): bool
    {
        return JobRoleAccess::allows(Permissions::NavShip, $user)
            && SiteAccess::canAccessSite($user, (int) $session->site_id);
    }

    public function delete(User $user, OutboundShippingSession $session): bool
    {
        return $this->update($user, $session);
    }
}
