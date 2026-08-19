<?php

namespace App\Policies;

use App\Models\TracingRequest;
use App\Models\User;
use App\Policies\Concerns\MaintainsMasterData;

class TracingRequestPolicy
{
    use MaintainsMasterData;

    /**
     * Recall broadcast acknowledgment links expose partner-facing URLs, so rotating
     * and revoking them stays with the tenant owner and master-data administrators
     * rather than every master-data maintainer.
     */
    public function manageAckLink(User $user, TracingRequest $tracingRequest): bool
    {
        return $this->isDeleter($user);
    }
}
