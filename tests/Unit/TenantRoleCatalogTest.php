<?php

namespace Tests\Unit;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
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
    public function three_pl_personas_are_distinct_from_pharmacy(): void
    {
        $threePl = collect(TenantRole::forProfile(TenantProfile::Logistics3pl))->map->value->all();
        $pharmacy = collect(TenantRole::forProfile(TenantProfile::Pharmacy))->map->value->all();

        $this->assertContains(TenantRole::WmsIntegrationSpecialist->value, $threePl);
        $this->assertEmpty(array_intersect(
            array_diff($threePl, [TenantRole::Owner->value]),
            array_diff($pharmacy, [TenantRole::Owner->value]),
        ));
    }

    #[Test]
    public function owner_capability_bundle_includes_all_nav_permissions(): void
    {
        $names = \App\Support\Auth\TenantRoleSeeder::permissionNamesFor(TenantRole::Owner);

        foreach (\App\Support\Auth\Permissions::navCapabilities() as $capability) {
            $this->assertContains($capability, $names);
        }
    }
}
