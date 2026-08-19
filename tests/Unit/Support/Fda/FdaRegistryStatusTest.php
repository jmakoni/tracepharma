<?php

namespace Tests\Unit\Support\Fda;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\FdaRegistryStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaRegistryStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        FdaProduct::query()->where('product_id', 'SSOR-REG-AUTH-PROD')->delete();
        FdaWddLicense::query()->where('license_number', 'SSOR-REG-LIC-1')->delete();
        FdaWddFacility::query()->where('name', 'SSOR REG DC')->delete();
        FdaOrganization::query()->whereIn('canonical_name', ['SSOR REG AUTH MFR', 'SSOR REG AUTH WDD'])->delete();

        parent::tearDown();
    }

    #[Test]
    public function establishment_status_follows_exclusion_then_expiration(): void
    {
        $excluded = new FdaEstablishment(['exclusion_flag' => true, 'expiration_date' => now()->addYear()]);
        $expired = new FdaEstablishment(['exclusion_flag' => false, 'expiration_date' => now()->subDay()]);
        $registered = new FdaEstablishment(['exclusion_flag' => false, 'expiration_date' => null]);

        $this->assertSame(FdaRegistryStatus::ESTABLISHMENT_EXCLUDED, FdaRegistryStatus::establishment($excluded));
        $this->assertSame(FdaRegistryStatus::ESTABLISHMENT_EXPIRED, FdaRegistryStatus::establishment($expired));
        $this->assertSame(FdaRegistryStatus::ESTABLISHMENT_REGISTERED, FdaRegistryStatus::establishment($registered));
    }

    #[Test]
    public function license_status_treats_inactive_as_delisted_before_expiry(): void
    {
        $delisted = new FdaWddLicense(['is_active' => false, 'expiration_date' => now()->addYear()]);
        $expired = new FdaWddLicense(['is_active' => true, 'expiration_date' => now()->subDay()]);
        $active = new FdaWddLicense(['is_active' => true, 'expiration_date' => null]);

        $this->assertSame(FdaRegistryStatus::LICENSE_DELISTED, FdaRegistryStatus::license($delisted));
        $this->assertSame(FdaRegistryStatus::LICENSE_EXPIRED, FdaRegistryStatus::license($expired));
        $this->assertSame(FdaRegistryStatus::LICENSE_ACTIVE, FdaRegistryStatus::license($active));
    }

    #[Test]
    public function import_run_outcome_is_failed_partial_or_success(): void
    {
        $failed = new FdaImportRun(['completed_at' => null, 'rows_skipped' => 0, 'rows_sent_to_review' => 0]);
        $partial = new FdaImportRun(['completed_at' => now(), 'rows_skipped' => 2, 'rows_sent_to_review' => 0]);
        $success = new FdaImportRun(['completed_at' => now(), 'rows_skipped' => 0, 'rows_sent_to_review' => 0]);

        $this->assertSame(FdaRegistryStatus::IMPORT_FAILED, FdaRegistryStatus::importRun($failed));
        $this->assertSame(FdaRegistryStatus::IMPORT_PARTIAL, FdaRegistryStatus::importRun($partial));
        $this->assertSame(FdaRegistryStatus::IMPORT_SUCCESS, FdaRegistryStatus::importRun($success));
    }

    #[Test]
    public function manufacturer_authorization_does_not_require_a_wdd_license(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR REG Auth Mfr',
            'canonical_name' => 'SSOR REG AUTH MFR',
            'name' => 'SSOR REG Auth Mfr',
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);

        FdaProduct::query()->create([
            'product_id' => 'SSOR-REG-AUTH-PROD',
            'product_ndc' => '88882-101',
            'name' => 'Auth Product',
            'fda_organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertStringContainsString('WDD license is not required', FdaRegistryStatus::organizationAuthorization($org));

        FdaProduct::query()->where('product_id', 'SSOR-REG-AUTH-PROD')->delete();
        FdaOrganization::query()->where('canonical_name', 'SSOR REG AUTH MFR')->delete();
    }

    #[Test]
    public function wholesaler_authorization_requires_an_active_facility_license(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR REG Auth Wdd',
            'canonical_name' => 'SSOR REG AUTH WDD',
            'name' => 'SSOR REG Auth Wdd',
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);

        $this->assertStringContainsString('Not authorized', FdaRegistryStatus::organizationAuthorization($org));

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => 'SSOR REG DC',
            'name' => 'SSOR REG DC',
            'address_fingerprint' => AddressFingerprint::fromWdd('1 Auth Way', 'Austin', 'TX', '78701'),
            'is_active' => true,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-REG-LIC-1',
            'jurisdiction' => 'TX',
            'is_active' => true,
        ]);

        $this->assertStringContainsString('active facility license', FdaRegistryStatus::organizationAuthorization($org->fresh()));

        FdaWddLicense::query()->where('license_number', 'SSOR-REG-LIC-1')->delete();
        FdaWddFacility::query()->where('name', 'SSOR REG DC')->delete();
        FdaOrganization::query()->where('canonical_name', 'SSOR REG AUTH WDD')->delete();
    }
}
