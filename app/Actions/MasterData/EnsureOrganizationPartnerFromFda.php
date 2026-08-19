<?php

namespace App\Actions\MasterData;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\TradingPartner;
use App\Support\Custody\TenantGlnSet;
use App\Support\Fda\FdaPrefill;
use App\Support\Fda\FdaTenantLink;
use App\Support\MasterData\TenantPartnerFdaLink;
use Illuminate\Support\Facades\Log;

/**
 * Find or create a tenant trading partner that mirrors an FDA organization.
 */
final class EnsureOrganizationPartnerFromFda
{
    public function handle(FdaOrganization $organization, ?PartnerType $partnerType = null): ?TradingPartner
    {
        if ((new TenantGlnSet)->contains($organization->gln)) {
            return null;
        }

        $type = $partnerType ?? $organization->partner_type ?? PartnerType::Manufacturer;
        $partner = $this->resolveExisting($organization);

        if ($partner === null) {
            $partner = TradingPartner::query()->create(
                array_merge(FdaPrefill::organizationAttributes($organization), [
                    'partner_type' => $type,
                ]),
            );
        } else {
            $partner->forceFill(TenantPartnerFdaLink::attributesFor($partner, $organization, $type))->save();
        }

        FdaTenantLink::stampPartner($partner, $organization);

        if ($type === PartnerType::Manufacturer) {
            app(ReconcilePendingManufacturerAuthorizations::class)->handle($partner);
        }

        if (app(CreateHqSiteForTradingPartner::class)->handle($partner) === null) {
            Log::warning('No headquarters site created for tenant trading partner: its GLN already belongs to another site.', [
                'trading_partner_id' => $partner->getKey(),
                'fda_organization_id' => $organization->getKey(),
                'gln' => $partner->gln,
            ]);
        }

        return $partner;
    }

    private function resolveExisting(FdaOrganization $organization): ?TradingPartner
    {
        $byFda = TradingPartner::query()
            ->where('fda_organization_id', $organization->getKey())
            ->first();

        if ($byFda !== null) {
            return $byFda;
        }

        if (blank($organization->gln)) {
            return null;
        }

        return TradingPartner::query()->where('gln', $organization->gln)->first();
    }
}
