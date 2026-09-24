<?php

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\OrganizationSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gs1IdentityStatus;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentityIncompleteBannerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function operations_hub_shows_identity_incomplete_when_gcp_is_blank(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setGln('0366159000026')
                ->setCompanyPrefix(null);
            $tenant->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $this->assertTrue(Gs1IdentityStatus::identityIncomplete());

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OperationsHub::class)
                ->assertSee('Identity incomplete')
                ->assertSee(OrganizationSettings::getUrl(panel: 'app'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function operations_hub_hides_identity_banner_when_gln_and_gcp_are_set(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)
                ->setGln('0366159000026')
                ->setCompanyPrefix('036615');
            $tenant->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $this->assertFalse(Gs1IdentityStatus::identityIncomplete());

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OperationsHub::class)
                ->assertDontSee('Identity incomplete');
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        $settings = TenantSettings::forTenant($tenant);
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant->fresh() ?? $tenant);
        }

        $current = $tenant->fresh() ?? $tenant;
        $current->forceFill([
            'gln' => $this->priorGln,
            'company_prefix' => $this->priorCompanyPrefix,
        ])->save();

        tenancy()->end();
    }
}
