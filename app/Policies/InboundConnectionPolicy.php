<?php

namespace App\Policies;

use App\Models\InboundConnection;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;

class InboundConnectionPolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InboundConnection $connection): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, InboundConnection $connection): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, InboundConnection $connection): bool
    {
        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
