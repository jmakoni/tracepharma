<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;

class DevicePolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Device $device): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, Device $device): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
