<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Epcis\EpcisSubscription;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;

class EpcisSubscriptionPolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EpcisSubscription $subscription): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, EpcisSubscription $subscription): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, EpcisSubscription $subscription): bool
    {
        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
