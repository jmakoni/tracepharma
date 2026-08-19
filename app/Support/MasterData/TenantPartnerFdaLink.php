<?php

namespace App\Support\MasterData;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\TradingPartner;

/**
 * Attributes to apply when ingest links an existing tenant partner to an FDA organization.
 */
final class TenantPartnerFdaLink
{
    /**
     * @return array<string, mixed>
     */
    public static function attributesFor(
        TradingPartner $partner,
        FdaOrganization $organization,
        PartnerType $partnerType,
    ): array {
        $attributes = [];
        $name = $organization->name ?: $organization->original_name ?: $organization->canonical_name;

        if (blank($partner->name) && filled($name)) {
            $attributes['name'] = $name;
        }

        if ($partner->fda_organization_id === null) {
            $attributes['fda_organization_id'] = $organization->getKey();
        }

        if (self::isUnclassified($partner)) {
            $attributes['partner_type'] = $partnerType;
        }

        return $attributes;
    }

    private static function isUnclassified(TradingPartner $partner): bool
    {
        return $partner->partner_type === null
            || $partner->partner_type === PartnerType::Other;
    }
}
