<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\AtpPartnerReadiness;
use App\Filament\App\Pages\ComplianceAlertCenter;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComplianceAlertCenterPageTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    #[Test]
    public function alert_center_and_atp_readiness_pages_render_for_owner(): void
    {
        $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            Livewire::test(ComplianceAlertCenter::class)
                ->assertSuccessful()
                ->assertSee('Compliance alert center')
                ->assertSee('not a live NABP Pulse feed');

            Livewire::test(AtpPartnerReadiness::class)
                ->assertSuccessful()
                ->assertSee('Partner ATP readiness')
                ->assertSee('manual Pulse/OCI evidence')
                ->assertSee('not a certified live Pulse/OCI directory API');
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function prepackager_atp_readiness_uses_repackager_diligence_title(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::Prepackager);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Prepackager);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertSame('Repackager ATP diligence.', AtpPartnerReadiness::getNavigationLabel());

            Livewire::test(AtpPartnerReadiness::class)
                ->assertSuccessful()
                ->assertSee('Repackager ATP diligence.')
                ->assertSee('manual Pulse/OCI evidence');
        } finally {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    private function initializeTenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => $profile->value,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => 'demo2.internal.vatengi.com']);
        }

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());

        return $tenant->fresh();
    }
}
