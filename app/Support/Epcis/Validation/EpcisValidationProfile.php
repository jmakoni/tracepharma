<?php

namespace App\Support\Epcis\Validation;

/**
 * Validation profiles that can be stacked/selected when checking an EPCIS document.
 *
 * DscsaMinimum is always conceptually applied; Gs1UsR12/Gs1UsR13 represent the
 * GS1 US EPCIS/DSCSA Implementation Guideline revision enforced as the "hard" profile.
 */
enum EpcisValidationProfile: string
{
    case DscsaMinimum = 'dscsa_minimum';
    case Gs1UsR12 = 'gs1us_r12';
    case Gs1UsR13 = 'gs1us_r13';

    public function label(): string
    {
        return match ($this) {
            self::DscsaMinimum => 'DSCSA Minimum',
            self::Gs1UsR12 => 'GS1 US Implementation Guideline R1.2',
            self::Gs1UsR13 => 'GS1 US Implementation Guideline R1.3',
        };
    }
}
