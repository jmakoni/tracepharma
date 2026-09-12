<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Models\PortalOrganization;
use App\Models\TradingPartner;

final class EnsurePortalOrganization
{
    /**
     * Idempotently ensure a portal organization exists for the trading partner.
     */
    public function handle(TradingPartner $partner): PortalOrganization
    {
        $org = PortalOrganization::query()
            ->where('trading_partner_id', $partner->getKey())
            ->first();

        if ($org !== null) {
            if ($org->name !== $partner->name) {
                $org->forceFill(['name' => (string) $partner->name])->save();
            }

            return $org->fresh() ?? $org;
        }

        return PortalOrganization::query()->create([
            'trading_partner_id' => $partner->getKey(),
            'name' => (string) $partner->name,
            'is_active' => true,
        ]);
    }
}
