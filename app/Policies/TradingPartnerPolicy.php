<?php

namespace App\Policies;

use App\Models\TradingPartner;
use App\Models\User;
use App\Policies\Concerns\MaintainsMasterData;
use App\Support\MasterData\TradingPartnerReferences;

class TradingPartnerPolicy
{
    use MaintainsMasterData;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TradingPartner $partner): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, TradingPartner $partner): bool
    {
        return $this->isMaintainer($user);
    }

    /**
     * Bulk deactivation authorizes once instead of per record.
     */
    public function updateAny(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    /**
     * Hard delete is the exception: allowed only for master-data owners and only while no
     * traceability record still names the partner. Everyone else deactivates instead.
     */
    public function delete(User $user, TradingPartner $partner): bool
    {
        return $this->isDeleter($user)
            && ! TradingPartnerReferences::isReferenced($partner);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }

    /**
     * The supplier portal link exposes this partner's open exception cases to an outside
     * party, so issuing, rotating and revoking it stays with the tenant owner and the
     * master-data administrators rather than every master-data maintainer.
     */
    public function managePortalLink(User $user, TradingPartner $partner): bool
    {
        return $this->isDeleter($user);
    }
}
