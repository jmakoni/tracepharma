<?php

namespace App\Actions\Demo;

use App\Actions\Catalog\EnsureMajorWholesalerFdaOrganizations;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Fda\AddressFingerprint;
use App\Support\Gs1\Ndc;

/**
 * Idempotent sample FDA registry data on the central connection.
 */
final class SeedCatalogMasterData
{
    public function handle(): void
    {
        $this->seed();
    }

    private function seed(): void
    {
        $pharmaInc = FdaOrganization::query()->firstOrCreate(
            ['name' => 'Catalog Pharma Inc'],
            [
                'original_name' => 'Catalog Pharma Inc',
                'canonical_name' => 'CATALOG PHARMA INC',
                'partner_type' => PartnerType::Manufacturer,
                'country_code' => 'US',
                'is_active' => true,
            ]
        );

        $atorvastatin = FdaProduct::query()->firstOrCreate(
            ['product_id' => 'DEMO-FDA-0001'],
            [
                'product_ndc' => '12345-678-90',
                'generic_name' => 'Atorvastatin Calcium',
                'brand_name' => 'Demo Atorvastatin',
                'name' => 'Catalog Atorvastatin 20 mg',
                'fda_organization_id' => $pharmaInc->id,
                'dosage_form' => 'TABLET',
                'strength' => '20 mg',
                'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
                'finished' => true,
                'is_active' => true,
            ]
        );

        if ($atorvastatin->fda_organization_id === null) {
            $atorvastatin->update(['fda_organization_id' => $pharmaInc->id]);
        }

        FdaProductPackaging::query()->firstOrCreate(
            ['gtin' => '00312345678901'],
            [
                'fda_product_id' => $atorvastatin->id,
                'package_ndc' => '12345-678-90',
                'ndc11' => Ndc::toNdc11('12345-678-90'),
                'description' => 'Catalog Atorvastatin 20 mg',
                'is_active' => true,
            ]
        );

        $amoxicillin = FdaProduct::query()->firstOrCreate(
            ['product_id' => 'DEMO-FDA-0002'],
            [
                'product_ndc' => '98765-432-10',
                'generic_name' => 'Amoxicillin',
                'brand_name' => 'Demo Amoxicillin',
                'name' => 'Catalog Amoxicillin 500 mg',
                'fda_organization_id' => $pharmaInc->id,
                'dosage_form' => 'CAPSULE',
                'strength' => '500 mg',
                'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
                'finished' => true,
                'is_active' => true,
            ]
        );

        FdaProductPackaging::query()->firstOrCreate(
            ['gtin' => '00398765432109'],
            [
                'fda_product_id' => $amoxicillin->id,
                'package_ndc' => '98765-432-10',
                'ndc11' => Ndc::toNdc11('98765-432-10'),
                'description' => 'Catalog Amoxicillin 500 mg',
                'is_active' => true,
            ]
        );

        $wholesaler = FdaOrganization::query()->firstOrCreate(
            ['gln' => '0614141000101'],
            [
                'original_name' => 'Catalog Wholesaler',
                'canonical_name' => 'CATALOG WHOLESALER',
                'name' => 'Catalog Wholesaler',
                'partner_type' => PartnerType::Wholesaler,
                'street_address' => '200 Catalog Avenue',
                'city' => 'Austin',
                'state_province' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
                'is_active' => true,
            ]
        );

        FdaOrganization::query()->firstOrCreate(
            ['gln' => '0614141000102'],
            [
                'original_name' => 'Catalog Manufacturer',
                'canonical_name' => 'CATALOG MANUFACTURER',
                'name' => 'Catalog Manufacturer',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
            ]
        );

        $facility = FdaWddFacility::query()->firstOrCreate(
            ['gln' => '0614141000101'],
            [
                'fda_organization_id' => $wholesaler->id,
                'facility_type' => FacilityType::Wdd,
                'facility_name' => 'Catalog Wholesaler HQ',
                'name' => 'Catalog Wholesaler HQ',
                'street_address' => '200 Catalog Avenue',
                'city' => 'Austin',
                'state_province' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
                'address_fingerprint' => AddressFingerprint::make(
                    '200 Catalog Avenue',
                    'Austin',
                    'TX',
                    '78701',
                    'US',
                ),
                'is_headquarters' => true,
                'is_active' => true,
            ]
        );

        FdaWddLicense::query()->firstOrCreate(
            [
                'fda_wdd_facility_id' => $facility->id,
                'jurisdiction' => 'TX',
                'license_number' => 'CAT-ATP-001',
            ],
            [
                'expiration_date' => now()->addYear()->toDateString(),
                'reporting_year' => now()->year,
                'is_active' => true,
            ]
        );

        app(EnsureMajorWholesalerFdaOrganizations::class)->handle();
    }
}
