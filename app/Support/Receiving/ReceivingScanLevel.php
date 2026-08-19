<?php

namespace App\Support\Receiving;

/**
 * The unit level a tenant profile is expected to scan first when receiving
 * (drives HUD copy in ReceivingPolicy::promptCopy()).
 */
enum ReceivingScanLevel: string
{
    case Pallet = 'pallet';
    case ToteOrCase = 'tote_or_case';
    case Case = 'case';

    public function label(): string
    {
        return match ($this) {
            self::Pallet => 'Pallet',
            self::ToteOrCase => 'Tote or case',
            self::Case => 'Case',
        };
    }
}
