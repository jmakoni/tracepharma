<?php

namespace App\Policies;

use App\Models\OutboundConnection;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;

class OutboundConnectionPolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, OutboundConnection $connection): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, OutboundConnection $connection): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, OutboundConnection $connection): bool
    {
        if ($connection->isSystemTemplate()) {
            return false;
        }

        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
