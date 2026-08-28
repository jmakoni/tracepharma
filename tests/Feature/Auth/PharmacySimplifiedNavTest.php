<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\PackWorkstation;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PharmacySimplifiedNavTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    #[Test]
    public function simplified_nav_hides_wholesaler_floor_pages_for_pharmacy(): void
    {
        $tenant = $this->initializeTenant();

        try {
            TenantSettings::forTenant($tenant)->setPharmacySimplifiedNavEnabled(true);
            $tenant->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertFalse(OperationsHub::shouldRegisterNavigation());
            $this->assertFalse(PackWorkstation::shouldRegisterNavigation());
            $this->assertFalse(Analytics::shouldRegisterNavigation());
            $this->assertFalse(TransferringSessionResource::shouldRegisterNavigation());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function disabling_simplified_nav_restores_wholesaler_pages(): void
    {
        $tenant = $this->initializeTenant();

        try {
            TenantSettings::forTenant($tenant)->setPharmacySimplifiedNavEnabled(false);
            $tenant->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertTrue(OperationsHub::shouldRegisterNavigation());
            $this->assertTrue(PackWorkstation::shouldRegisterNavigation());
        } finally {
            tenancy()->end();
        }
    }

    private function initializeTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => 'demo2.internal.vatengi.com']);
        }

        $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
