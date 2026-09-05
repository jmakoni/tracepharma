<?php

namespace App\Policies;

use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;

class EpcisDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()
            && JobRoleAccess::allows(Permissions::NavReceive, $user);
    }

    public function view(User $user, EpcisDocument $document): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return SiteAccess::canAccessShipToSite($user, $document->ship_to_site_id !== null
            ? (int) $document->ship_to_site_id
            : null);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EpcisDocument $document): bool
    {
        return false;
    }

    public function delete(User $user, EpcisDocument $document): bool
    {
        return false;
    }
}
