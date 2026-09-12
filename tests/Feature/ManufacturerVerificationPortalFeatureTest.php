<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManufacturerVerificationPortalFeatureTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function manufacturer_portal_enables_without_vrs_requestor_ui(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorProfile = $tenant->profile;
        $priorPortal = TenantSettings::forTenant($tenant)->manufacturerVerificationPortalEnabled();

        try {
            $this->setProfile($tenant, TenantProfile::Manufacturer);
            TenantSettings::forTenant(tenant())->setManufacturerVerificationPortalEnabled(true);
            tenant()?->save();

            $features = TenantFeatures::forTenant(tenant());

            $this->assertFalse($features->supportsVrs());
            $this->assertTrue($features->supportsVrsResponder());
            $this->assertTrue($features->supportsManufacturerVerificationPortal());
        } finally {
            TenantSettings::forTenant(tenant())->setManufacturerVerificationPortalEnabled($priorPortal);
            tenant()?->save();
            $this->setProfile($tenant, $priorProfile instanceof TenantProfile ? $priorProfile : TenantProfile::from((string) $priorProfile));
            tenancy()->end();
        }
    }

    #[Test]
    public function manufacturer_portal_off_when_setting_disabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorProfile = $tenant->profile;
        $priorPortal = TenantSettings::forTenant($tenant)->manufacturerVerificationPortalEnabled();

        try {
            $this->setProfile($tenant, TenantProfile::Manufacturer);
            TenantSettings::forTenant(tenant())->setManufacturerVerificationPortalEnabled(false);
            tenant()?->save();

            $features = TenantFeatures::forTenant(tenant());

            $this->assertFalse($features->supportsVrs());
            $this->assertFalse($features->supportsManufacturerVerificationPortal());
        } finally {
            TenantSettings::forTenant(tenant())->setManufacturerVerificationPortalEnabled($priorPortal);
            tenant()?->save();
            $this->setProfile($tenant, $priorProfile instanceof TenantProfile ? $priorProfile : TenantProfile::from((string) $priorProfile));
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_and_wholesaler_portal_still_follow_vrs_plus_setting(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $priorProfile = $tenant->profile;
        $priorPortal = TenantSettings::forTenant($tenant)->manufacturerVerificationPortalEnabled();

        try {
            foreach ([TenantProfile::Pharmacy, TenantProfile::DrugWholesaler] as $profile) {
                $this->setProfile($tenant, $profile);
                TenantSettings::forTenant(tenant())->setManufacturerVerificationPortalEnabled(true);
                tenant()?->save();

                $features = TenantFeatures::forTenant(tenant());

                $this->assertTrue($features->supportsVrs(), $profile->value);
                $this->assertTrue($features->supportsManufacturerVerificationPortal(), $profile->value);

                TenantSettings::forTenant(tenant())->setManufacturerVerificationPortalEnabled(false);
                tenant()?->save();

                $this->assertFalse(
                    TenantFeatures::forTenant(tenant())->supportsManufacturerVerificationPortal(),
                    $profile->value.' with setting off',
                );
            }
        } finally {
            TenantSettings::forTenant(tenant())->setManufacturerVerificationPortalEnabled($priorPortal);
            tenant()?->save();
            $this->setProfile($tenant, $priorProfile instanceof TenantProfile ? $priorProfile : TenantProfile::from((string) $priorProfile));
            tenancy()->end();
        }
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill(['profile' => $profile])->save();
        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Organization',
                'profile' => TenantProfile::DrugWholesaler->value,
            ]));
        }

        if (! self::$demo2TenantReady) {
            if (! $tenant->domains()->where('domain', self::DEMO2_DOMAIN)->exists()) {
                $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
            }

            $tenant->forceFill(['tenancy_db_name' => self::DEMO2_DATABASE])->save();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
