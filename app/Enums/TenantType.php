<?php

namespace App\Enums;

use App\Support\TenantFeatures;

/**
 * Coarse tenant classification derived from {@see TenantProfile}.
 * Use for IA / Organization Settings labels — not for capability gating
 * ({@see TenantFeatures} remains profile-driven).
 */
enum TenantType: string
{
    case Pharmacy = 'pharmacy';
    case Distributor = 'distributor';
    case ThreePl = 'three_pl';

    public function label(): string
    {
        return match ($this) {
            self::Pharmacy => 'Pharmacy',
            self::Distributor => 'Distributor',
            self::ThreePl => '3PL / Logistics',
        };
    }
}
