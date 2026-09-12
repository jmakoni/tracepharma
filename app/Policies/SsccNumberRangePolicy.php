<?php

namespace App\Policies;

use App\Models\SsccNumberRange;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;
use App\Support\Auth\SiteAccess;

class SsccNumberRangePolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SsccNumberRange $ssccNumberRange): bool
    {
        if ($ssccNumberRange->site_id === null) {
            return true;
        }

        return SiteAccess::canAccessSite($user, (int) $ssccNumberRange->site_id);
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, SsccNumberRange $ssccNumberRange): bool
    {
        if (! $this->isMaintainer($user)) {
            return false;
        }

        if ($ssccNumberRange->site_id === null) {
            return true;
        }

        return SiteAccess::canAccessSite($user, (int) $ssccNumberRange->site_id);
    }

    public function delete(User $user, SsccNumberRange $ssccNumberRange): bool
    {
        return $this->isDeleter($user) && $this->view($user, $ssccNumberRange);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
