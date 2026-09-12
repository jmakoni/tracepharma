<?php

namespace Tests\Unit;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Support\Auth\Permissions;
use App\Support\Auth\SupportEngineerEmail;
use App\Support\Auth\TenantRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TenantRoleCatalogTest extends TestCase
{
    #[Test]
    public function each_profile_always_includes_owner(): void
    {
        foreach (TenantProfile::cases() as $profile) {
            $this->assertContains(
                TenantRole::Owner,
                TenantRole::forProfile($profile),
                $profile->value
            );
        }
    }

    #[Test]
    public function each_profile_includes_support_engineer(): void
    {
        foreach (TenantProfile::cases() as $profile) {
            $this->assertContains(
                TenantRole::SupportEngineer,
                TenantRole::forProfile($profile),
                $profile->value
            );
        }
    }

    #[Test]
    public function dental_and_wholesaler_include_floor_roles(): void
    {
        foreach ([TenantProfile::DentalMedicalSupply, TenantProfile::DrugWholesaler] as $profile) {
            $roles = TenantRole::forProfile($profile);
            $this->assertContains(TenantRole::ReceivingTechnician, $roles, $profile->value);
            $this->assertContains(TenantRole::OutboundPickAndPackLead, $roles, $profile->value);
            $this->assertContains(TenantRole::InboundExceptionCoordinator, $roles, $profile->value);
        }
    }

    #[Test]
    public function three_pl_includes_floor_wms_and_verify_roles(): void
    {
        $roles = TenantRole::forProfile(TenantProfile::Logistics3pl);

        $this->assertContains(TenantRole::ReceivingTechnician, $roles);
        $this->assertContains(TenantRole::OutboundPickAndPackLead, $roles);
        $this->assertContains(TenantRole::InboundExceptionCoordinator, $roles);
        $this->assertContains(TenantRole::AtpVerificationManager, $roles);
        $this->assertContains(TenantRole::VrsAnalyst, $roles);
        $this->assertContains(TenantRole::WmsIntegrationSpecialist, $roles);
        $this->assertContains(TenantRole::QuarantineAndReturnsSpecialist, $roles);
        $this->assertNotContains(TenantRole::DispensingPharmacist, $roles);
        $this->assertNotContains(TenantRole::PharmacyInventoryManager, $roles);
    }

    #[Test]
    public function three_pl_keeps_wms_specialist_absent_from_pharmacy(): void
    {
        $threePl = collect(TenantRole::forProfile(TenantProfile::Logistics3pl))->map->value->all();
        $pharmacy = collect(TenantRole::forProfile(TenantProfile::Pharmacy))->map->value->all();

        $this->assertContains(TenantRole::WmsIntegrationSpecialist->value, $threePl);
        $this->assertNotContains(TenantRole::WmsIntegrationSpecialist->value, $pharmacy);
        $this->assertNotContains(TenantRole::DispensingPharmacist->value, $threePl);
    }

    #[Test]
    public function owner_capability_bundle_includes_all_nav_permissions(): void
    {
        $names = TenantRoleSeeder::permissionNamesFor(TenantRole::Owner);

        foreach (Permissions::navCapabilities() as $capability) {
            $this->assertContains($capability, $names);
        }
    }

    #[Test]
    public function support_engineer_has_full_tenant_app_permissions(): void
    {
        $this->assertSame(
            Permissions::tenantAppPermissions(),
            TenantRoleSeeder::permissionNamesFor(TenantRole::SupportEngineer),
        );
    }

    #[Test]
    public function support_engineer_email_allows_exact_tracepharma_domain_only(): void
    {
        $this->assertTrue(SupportEngineerEmail::isAllowed('alex@tracepharma.io'));
        $this->assertTrue(SupportEngineerEmail::isAllowed('Alex@TracePharma.io'));
        $this->assertFalse(SupportEngineerEmail::isAllowed('alex@gmail.com'));
        $this->assertFalse(SupportEngineerEmail::isAllowed('alex@mail.tracepharma.io'));
        $this->assertFalse(SupportEngineerEmail::isAllowed('alex@tracepharma.com'));
    }

    #[Test]
    public function support_engineer_label_omits_tracepharma_brand_prefix(): void
    {
        $this->assertSame('Support Engineer', TenantRole::SupportEngineer->label());
        $this->assertSame('support_engineer', TenantRole::SupportEngineer->value);
    }

    #[Test]
    public function jit_default_never_returns_owner_or_support_engineer(): void
    {
        foreach (TenantProfile::cases() as $profile) {
            $role = TenantRole::jitDefaultForProfile($profile);
            $this->assertTrue($role->isJitAssignable(), $profile->value);
            $this->assertNotSame(TenantRole::Owner, $role, $profile->value);
            $this->assertNotSame(TenantRole::SupportEngineer, $role, $profile->value);
        }
    }

    #[Test]
    public function buying_group_jit_default_is_buying_group_member(): void
    {
        $this->assertSame(
            TenantRole::BuyingGroupMember,
            TenantRole::jitDefaultForProfile(TenantProfile::BuyingGroup),
        );
        $this->assertSame([], TenantRoleSeeder::permissionNamesFor(TenantRole::BuyingGroupMember));
        $this->assertArrayNotHasKey(
            TenantRole::Owner->value,
            TenantRole::jitOptionsForProfile(TenantProfile::BuyingGroup),
        );
        $this->assertArrayNotHasKey(
            TenantRole::SupportEngineer->value,
            TenantRole::jitOptionsForProfile(TenantProfile::BuyingGroup),
        );
    }
}
