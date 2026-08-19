<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Policies\Concerns\MaintainsMasterData;
use App\Support\MasterData\SiteReferences;

class SitePolicy
{
    use MaintainsMasterData;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Site $site): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, Site $site): bool
    {
        return $this->isMaintainer($user);
    }

    /**
     * Bulk edits authorize once instead of per record.
     */
    public function updateAny(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    /**
     * Hard delete is the exception: allowed only for master-data owners and only while no
     * traceability record still names the location. Everyone else deactivates instead.
     */
    public function delete(User $user, Site $site): bool
    {
        return $this->isDeleter($user)
            && ! SiteReferences::isReferenced($site);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
