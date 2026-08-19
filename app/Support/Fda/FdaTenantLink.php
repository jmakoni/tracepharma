<?php

namespace App\Support\Fda;

use App\Models\AtpLicense;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Product;
use App\Models\Site;
use App\Models\TradingPartner;

/**
 * Tenant stamps onto the FDA registry. FDA ids only — no catalog fallback.
 */
final class FdaTenantLink
{
    public static function organizationId(TradingPartner $partner): ?int
    {
        return $partner->fda_organization_id ? (int) $partner->fda_organization_id : null;
    }

    public static function establishmentId(Site $site): ?int
    {
        return $site->fda_establishment_id ? (int) $site->fda_establishment_id : null;
    }

    public static function wddFacilityId(Site $site): ?int
    {
        return $site->fda_wdd_facility_id ? (int) $site->fda_wdd_facility_id : null;
    }

    public static function wddLicenseId(AtpLicense $license): ?int
    {
        return $license->fda_wdd_license_id ? (int) $license->fda_wdd_license_id : null;
    }

    public static function packagingId(Product $product): ?int
    {
        return $product->fda_product_packaging_id ? (int) $product->fda_product_packaging_id : null;
    }

    public static function stampPartner(TradingPartner $partner, FdaOrganization $org): void
    {
        $partner->forceFill(['fda_organization_id' => $org->id])->save();
    }

    public static function stampSiteFromEstablishment(Site $site, FdaEstablishment $establishment): void
    {
        $site->forceFill([
            'fda_establishment_id' => $establishment->id,
            'fda_wdd_facility_id' => null,
        ])->save();
    }

    public static function stampSiteFromWddFacility(Site $site, FdaWddFacility $facility): void
    {
        $site->forceFill([
            'fda_wdd_facility_id' => $facility->id,
            'fda_establishment_id' => null,
        ])->save();
    }

    public static function stampLicense(AtpLicense $license, FdaWddLicense $wddLicense): void
    {
        $license->forceFill(['fda_wdd_license_id' => $wddLicense->id])->save();
    }

    public static function stampProduct(
        Product $product,
        FdaProduct $listing,
        FdaProductPackaging $pkg,
    ): void {
        $product->forceFill([
            'fda_product_id' => $listing->id,
            'fda_product_packaging_id' => $pkg->id,
        ])->save();
    }
}
