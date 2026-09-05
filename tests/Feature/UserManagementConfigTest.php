<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\App\Resources\Users\UserResource;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Support\Auth\Permissions;
use App\Support\Auth\TracepharmaBreezyCore;
use Filament\Panel;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class UserManagementConfigTest extends TestCase
{
    #[Test]
    #[DataProvider('pharmacyRoles')]
    public function pharmacy_profile_includes_expected_roles(TenantRole $role): void
    {
        $this->assertContains($role, TenantRole::forProfile(TenantProfile::Pharmacy));
    }

    /**
     * @return array<string, array{0: TenantRole}>
     */
    public static function pharmacyRoles(): array
    {
        return [
            'owner' => [TenantRole::Owner],
            'receiving' => [TenantRole::ReceivingTechnician],
            'pharmacist' => [TenantRole::DispensingPharmacist],
            'inventory' => [TenantRole::PharmacyInventoryManager],
            'sysadmin' => [TenantRole::PharmacySystemAdministrator],
        ];
    }

    #[Test]
    public function manufacturer_profile_does_not_include_pharmacy_personas(): void
    {
        $roles = TenantRole::forProfile(TenantProfile::Manufacturer);

        $this->assertContains(TenantRole::Owner, $roles);
        $this->assertContains(TenantRole::PackagingLineOperator, $roles);
        $this->assertNotContains(TenantRole::ReceivingTechnician, $roles);
    }

    #[Test]
    public function buying_group_gets_owner_and_support_engineer(): void
    {
        $this->assertSame(
            [TenantRole::Owner, TenantRole::SupportEngineer],
            TenantRole::forProfile(TenantProfile::BuyingGroup)
        );
    }

    #[Test]
    public function wholesaler_and_dental_share_distribution_personas(): void
    {
        $this->assertSame(
            TenantRole::forProfile(TenantProfile::DrugWholesaler),
            TenantRole::forProfile(TenantProfile::DentalMedicalSupply)
        );
        $this->assertContains(TenantRole::AtpVerificationManager, TenantRole::forProfile(TenantProfile::DrugWholesaler));
    }

    #[Test]
    public function admin_roles_and_permission_constants_are_stable(): void
    {
        $this->assertSame('platform_admin', AdminRole::PlatformAdmin->value);
        $this->assertSame('support', AdminRole::Support->value);
        $this->assertSame('users.manage', Permissions::UsersManage);
        $this->assertSame('admins.manage', Permissions::AdminsManage);
        $this->assertSame('tenants.manage', Permissions::TenantsManage);
    }

    #[Test]
    public function user_and_admin_resources_are_settings_nav_and_deny_create_when_unauthenticated(): void
    {
        $userNav = (new ReflectionClass(UserResource::class))->getProperty('navigationGroup');
        $userNav->setAccessible(true);
        $adminNav = (new ReflectionClass(AdminResource::class))->getProperty('navigationGroup');
        $adminNav->setAccessible(true);

        $this->assertSame('Settings', $userNav->getValue());
        $this->assertSame('Settings', $adminNav->getValue());
        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(AdminResource::canAccess());
    }

    #[Test]
    public function app_and_admin_panels_use_tracepharma_brand_logos(): void
    {
        foreach ([
            'logo.svg',
            'logo-dark.svg',
            'logo-mark.svg',
            'logo-mono.svg',
        ] as $file) {
            $this->assertFileExists(public_path('images/brand/'.$file));
        }

        $appPanel = (new AppPanelProvider(app()))->panel(Panel::make());
        $adminPanel = (new AdminPanelProvider(app()))->panel(Panel::make());

        foreach ([$appPanel, $adminPanel] as $panel) {
            $this->assertSame('TracePharma', $panel->getBrandName());
            $this->assertIsString($panel->getBrandLogo());
            $this->assertStringContainsString('images/brand/logo.svg', (string) $panel->getBrandLogo());
            $this->assertStringContainsString('images/brand/logo-dark.svg', (string) $panel->getDarkModeBrandLogo());
            $this->assertStringContainsString('images/brand/logo-mark.svg', (string) $panel->getFavicon());
            $this->assertSame('2.25rem', $panel->getBrandLogoHeight());
        }
    }

    #[Test]
    public function breezy_plugins_enable_avatars_two_factor_and_passkeys(): void
    {
        $appPanel = (new AppPanelProvider(app()))->panel(Panel::make());
        $adminPanel = (new AdminPanelProvider(app()))->panel(Panel::make());

        $appBreezy = $this->breezyFrom($appPanel);
        $adminBreezy = $this->breezyFrom($adminPanel);

        $this->assertInstanceOf(TracepharmaBreezyCore::class, $appBreezy);
        $this->assertInstanceOf(TracepharmaBreezyCore::class, $adminBreezy);
        $this->assertTrue($appBreezy->hasAvatars());
        $this->assertTrue($adminBreezy->hasAvatars());
        $this->assertTrue($appBreezy->twoFactorAuthenticationEnabled());
        $this->assertTrue($adminBreezy->twoFactorAuthenticationEnabled());
        $this->assertTrue($this->breezyPasskeysEnabled($appBreezy));
        $this->assertTrue($this->breezyPasskeysEnabled($adminBreezy));
    }

    private function breezyFrom(Panel $panel): BreezyCore
    {
        foreach ($panel->getPlugins() as $plugin) {
            if ($plugin instanceof BreezyCore) {
                return $plugin;
            }
        }

        $this->fail('Breezy plugin not registered on panel '.$panel->getId());
    }

    private function breezyPasskeysEnabled(BreezyCore $breezy): bool
    {
        $reflection = new ReflectionClass($breezy);
        while ($reflection !== false) {
            if ($reflection->hasProperty('passkeys')) {
                $property = $reflection->getProperty('passkeys');
                $property->setAccessible(true);

                return (bool) $property->getValue($breezy);
            }
            $reflection = $reflection->getParentClass();
        }

        return false;
    }
}
