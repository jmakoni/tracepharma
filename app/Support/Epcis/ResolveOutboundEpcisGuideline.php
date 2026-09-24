<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Enums\EpcisGuideline;
use App\Models\TradingPartner;

final class ResolveOutboundEpcisGuideline
{
    public static function forPartner(?TradingPartner $partner): EpcisGuideline
    {
        return $partner?->epcis_guideline ?? EpcisGuideline::R12;
    }
}
