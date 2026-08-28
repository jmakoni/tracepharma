<?php

namespace App\Policies;

use App\Models\BuyingGroupMember;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;

class BuyingGroupMemberPolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BuyingGroupMember $member): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, BuyingGroupMember $member): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, BuyingGroupMember $member): bool
    {
        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
