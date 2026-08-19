<?php

namespace App\Actions\MasterData;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\TradingPartner;
use App\Support\Custody\TenantGlnSet;
use App\Support\MasterData\TenantPartnerCatalogLink;
use Illuminate\Support\Facades\Log;

/**
 * Find or create a tenant manufacturer partner that mirrors an FDA labeler.
 */
final class EnsureManufacturerPartnerFromCatalog
{
    /**
     * Null when the FDA organization is the tenant itself — we are never
     * our own trading partner ({@see TenantGlnSet}) — or when the id no longer
     * resolves.
     */
    public function handle(int|FdaOrganization $organization): ?TradingPartner
    {
        $org = $organization instanceof FdaOrganization
            ? $organization
            : FdaOrganization::query()->find($organization);

        if ($org === null) {
            Log::warning('No tenant manufacturer partner mirrored: the FDA organization no longer exists.', [
                'fda_organization_id' => $organization,
            ]);

            return null;
        }

        if ((new TenantGlnSet)->contains($org->gln)) {
            return null;
        }

        $partner = $this->resolveExisting($org);

        if ($partner === null) {
            $partner = TradingPartner::query()->create(
                array_merge($this->partnerAttributesFromOrganization($org), [
                    'partner_type' => PartnerType::Manufacturer,
                ]),
            );
        }

        app(ReconcilePendingManufacturerAuthorizations::class)->handle($partner);

        if (app(CreateHqSiteForTradingPartner::class)->handle($partner) === null) {
            Log::warning('No headquarters site created for tenant trading partner: its GLN already belongs to another site.', [
                'trading_partner_id' => $partner->getKey(),
                'fda_organization_id' => $org->getKey(),
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

        $byGln = TradingPartner::query()
            ->where('gln', $organization->gln)
            ->first();

        if ($byGln === null) {
            return null;
        }

        $byGln->forceFill(TenantPartnerCatalogLink::attributesFor(
            $byGln,
            $organization,
            PartnerType::Manufacturer,
        ))->save();

        return $byGln->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerAttributesFromOrganization(FdaOrganization $organization): array
    {
        return [
            'fda_organization_id' => $organization->getKey(),
            'name' => $organization->name,
            'doing_business_as' => $organization->doing_business_as,
            'description' => $organization->description,
            'gln' => $organization->gln,
            'partner_type' => $organization->partner_type,
            'street_address' => $organization->street_address,
            'street_address_2' => $organization->street_address_2,
            'city' => $organization->city,
            'state' => $organization->state_province,
            'zipcode' => $organization->postal_code,
            'country_code' => $organization->country_code,
            'timezone' => $organization->timezone,
            'altitude' => $organization->altitude,
            'latitude' => $organization->latitude,
            'longitude' => $organization->longitude,
            'logo' => $organization->logo,
            'website' => $organization->website,
            'telephone' => $organization->telephone,
            'email' => $organization->email,
            'fax' => $organization->fax,
            'is_active' => $organization->is_active ?? true,
        ];
    }
}
