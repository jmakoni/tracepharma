<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SsccLabel;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;

class SsccLabelPolicy
{
    public function viewAny(User $user): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return ($features->supportsPacking() || $features->supportsSsccLabeling())
            && JobRoleAccess::allows(Permissions::NavMasterData, $user);
    }

    public function view(User $user, SsccLabel $label): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return true;
        }

        $siteId = $label->batch?->commission_site_id;
        if ($siteId === null) {
            $label->loadMissing('batch');
            $siteId = $label->batch?->commission_site_id;
        }

        return $siteId !== null
            && SiteAccess::canAccessSite($user, (int) $siteId);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SsccLabel $label): bool
    {
        return false;
    }

    public function delete(User $user, SsccLabel $label): bool
    {
        return false;
    }
}
