<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\WholesalerIntegrationPackPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Integrations\WholesalerIntegrationPack;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WholesalerIntegrationPackPageTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function checklist_exposes_wms_ship_confirm_items(): void
    {
        $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            $items = app(WholesalerIntegrationPack::class)->items();
            $ids = array_column($items, 'id');

            $this->assertContains('wms_bridge_key', $ids);
            $this->assertContains('wms_token', $ids);
            $this->assertGreaterThanOrEqual(0, app(WholesalerIntegrationPack::class)->score());
        } finally {
            $this->restoreProfile();
        }
    }

    #[Test]
    public function checklist_page_renders_for_owner(): void
    {
        $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            Livewire::test(WholesalerIntegrationPackPage::class)
                ->assertSuccessful()
                ->assertSee('Wholesaler / WMS integration pack')
                ->assertSee('ship-confirm');
        } finally {
            $this->restoreProfile();
        }
    }

    private function initializeTenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => 'demo2.internal.vatengi.com']);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => 'demo2.internal.vatengi.com']);
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function restoreProfile(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $tenant = tenant();
        if ($this->priorProfile !== null && $tenant !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
