<?php

namespace App\Support\MasterData;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\TradingPartner;

/**
 * Attributes to apply when ingest links an existing tenant partner (matched by GLN)
 * to a central FDA organization.
 *
 * Ingest may enrich, never override a deliberate tenant decision: `is_active` is left
 * alone so a deactivated partner stays deactivated, `name` and `partner_type` are only
 * filled while the tenant has curated neither, and the FDA link is only set when
 * still empty.
 */
final class TenantPartnerCatalogLink
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

        if (blank($partner->name) && filled($organization->name)) {
            $attributes['name'] = $organization->name;
        }

        if ($partner->fda_organization_id === null) {
            $attributes['fda_organization_id'] = $organization->getKey();
        }

        if (self::isUnclassified($partner)) {
            $attributes['partner_type'] = $partnerType;
        }

        return $attributes;
    }

    /**
     * `trading_partners.partner_type` is NOT NULL, so `PartnerType::Other` is the placeholder
     * the schema backfill and the EPCIS profile fallback use for "role still unknown".
     */
    private static function isUnclassified(TradingPartner $partner): bool
    {
        return $partner->partner_type === null
            || $partner->partner_type === PartnerType::Other;
    }
}
