<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\PmsIntegrationChecklistPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\PmsIntegrationChecklist;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PmsIntegrationChecklistPageTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    #[Test]
    public function checklist_exposes_dispense_check_items(): void
    {
        $this->initializeTenant();

        try {
            $items = app(PmsIntegrationChecklist::class)->items();
            $ids = array_column($items, 'id');

            $this->assertContains('dispense_token', $ids);
            $this->assertContains('receiving_state', $ids);
            $this->assertGreaterThanOrEqual(0, app(PmsIntegrationChecklist::class)->score());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function checklist_page_renders_for_owner(): void
    {
        $this->initializeTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            Livewire::test(PmsIntegrationChecklistPage::class)
                ->assertSuccessful()
                ->assertSee('PMS integration checklist')
                ->assertSee('dispense-check');
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
