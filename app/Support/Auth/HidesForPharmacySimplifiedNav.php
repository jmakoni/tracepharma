<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\TenantFeatures;

/**
 * Nav registration helper for wholesaler-heavy surfaces hidden in pharmacy simplified mode.
 */
trait HidesForPharmacySimplifiedNav
{
    public static function shouldRegisterNavigation(): bool
    {
        if (! TenantFeatures::forTenant(tenant())->showsWholesaleOperationsNav()) {
            return false;
        }

        return parent::shouldRegisterNavigation();
    }
}
