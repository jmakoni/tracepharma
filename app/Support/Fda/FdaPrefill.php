<?php

namespace App\Support\Fda;

use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\TradingPartner;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Map central FDA registry rows into tenant master-data form attributes.
 */
final class FdaPrefill
{
    /**
     * @return array<string, mixed>
     */
    public static function organizationAttributes(FdaOrganization $organization): array
    {
        return self::normalize([
            'fda_organization_id' => $organization->getKey(),
            'name' => $organization->name ?: $organization->original_name ?: $organization->canonical_name,
            'doing_business_as' => $organization->doing_business_as,
            'description' => $organization->description,
            'gln' => $organization->gln,
            'duns_number' => $organization->duns_number,
            'partner_type' => $organization->partner_type,
            'street_address' => $organization->street_address,
            'street_address_2' => $organization->street_address_2,
            'city' => $organization->city,
            'state' => $organization->state_province,
            'zipcode' => $organization->postal_code,
            'country_code' => $organization->country_code ?: 'US',
            'timezone' => $organization->timezone,
            'altitude' => $organization->altitude,
            'latitude' => $organization->latitude,
            'longitude' => $organization->longitude,
            'logo' => $organization->logo,
            'website' => $organization->website,
            'telephone' => $organization->telephone,
            'email' => $organization->email,
            'fax' => $organization->fax,
            'is_active' => $organization->is_active,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function establishmentAttributes(FdaEstablishment $establishment): array
    {
        return self::normalize([
            'fda_establishment_id' => $establishment->getKey(),
            'fda_wdd_facility_id' => null,
            'name' => $establishment->name ?: $establishment->firm_name,
            'code' => $establishment->code,
            'gln' => $establishment->gln,
            'duns_number' => $establishment->duns_number,
            'dea_number' => $establishment->dea_number,
            'hin_number' => $establishment->hin_number,
            'is_headquarters' => $establishment->is_headquarters,
            'street_address' => $establishment->street_address,
            'street_address_2' => $establishment->street_address_2,
            'city' => $establishment->city,
            'state' => $establishment->state_province,
            'zipcode' => $establishment->postal_code,
            'country_code' => $establishment->country_code,
            'timezone' => $establishment->timezone,
            'altitude' => $establishment->altitude,
            'latitude' => $establishment->latitude,
            'longitude' => $establishment->longitude,
            'is_active' => $establishment->is_active,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function wddFacilityAttributes(FdaWddFacility $facility): array
    {
        // Keep fda_establishment_id when the operator already chose an establishment for
        // the same organization — both stamps are valid on the tenant site.
        return self::normalize([
            'fda_wdd_facility_id' => $facility->getKey(),
            'name' => $facility->name ?: $facility->facility_name,
            'code' => $facility->code,
            'gln' => $facility->gln,
            'duns_number' => $facility->duns_number,
            'dea_number' => $facility->dea_number,
            'hin_number' => $facility->hin_number,
            'is_headquarters' => $facility->is_headquarters,
            'street_address' => $facility->street_address,
            'street_address_2' => $facility->street_address_2,
            'city' => $facility->city,
            'state' => $facility->state_province,
            'zipcode' => $facility->postal_code,
            'country_code' => $facility->country_code,
            'timezone' => $facility->timezone,
            'altitude' => $facility->altitude,
            'latitude' => $facility->latitude,
            'longitude' => $facility->longitude,
            'is_active' => $facility->is_active,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function packagingAttributes(FdaProductPackaging $packaging): array
    {
        $listing = $packaging->relationLoaded('product')
            ? $packaging->product
            : $packaging->product()->first();

        return self::normalize([
            'fda_product_packaging_id' => $packaging->getKey(),
            'fda_product_id' => $packaging->fda_product_id,
            'gtin' => $packaging->gtin,
            'name' => self::listingName($listing),
            'dosage_form' => $listing?->dosage_form,
            'strength' => $listing?->strength ?: $listing?->activeIngredientStrength(),
            'trading_partner_id' => self::tenantManufacturerPartnerId($listing?->fda_organization_id),
            'ndc' => $listing?->product_ndc,
            'package_ndc' => $packaging->package_ndc,
            'ndc11' => $packaging->ndc11,
            'is_active' => $packaging->is_active,
        ]);
    }

    private static function listingName(?FdaProduct $listing): ?string
    {
        if ($listing === null) {
            return null;
        }

        return $listing->name ?: $listing->brand_name ?: $listing->generic_name;
    }

    private static function tenantManufacturerPartnerId(?int $organizationId): ?int
    {
        if ($organizationId === null) {
            return null;
        }

        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return null;
        }

        return TradingPartner::query()
            ->where('fda_organization_id', $organizationId)
            ->value('id');
    }

    /**
     * Overlay registry attributes without blanking a GLN the operator already typed.
     *
     * Many FDA establishments and WDD facilities have no GLN. array_merge would
     * replace the form value with null and the site would be stored without one.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $prefill
     * @return array<string, mixed>
     */
    public static function mergeKeepingFilledGln(array $data, array $prefill): array
    {
        $merged = array_merge($data, $prefill);

        foreach (['gln', 'sgln', 'duns_number', 'dea_number', 'hin_number'] as $key) {
            if (blank($merged[$key] ?? null) && filled($data[$key] ?? null)) {
                $merged[$key] = $data[$key];
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function isBlankIdentityValue(string $key, mixed $value): bool
    {
        return in_array($key, ['gln', 'sgln', 'duns_number', 'dea_number', 'hin_number'], true) && blank($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function normalize(array $attributes): array
    {
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        foreach ($attributes as $key => $value) {
            if ($value instanceof BackedEnum) {
                $attributes[$key] = $value->value;
            } elseif ($value instanceof CarbonInterface) {
                $attributes[$key] = $value->toDateString();
            }
        }

        return $attributes;
    }
}
