<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\OperationsHub;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\AnalyticsMetrics;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?bool $priorJobRolesEnabled = null;

    #[Test]
    public function owner_can_access_analytics_on_demo2(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $this->assertTrue(Analytics::canAccess());

            Livewire::test(Analytics::class)
                ->assertOk()
                ->assertSee('Analytics')
                ->assertSee('As of');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function user_without_job_role_cannot_access_when_job_roles_are_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $user = User::factory()->create();
            $this->actingAs($user);

            $this->assertFalse(Analytics::canAccess());

            Livewire::test(Analytics::class)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function metrics_methods_return_arrays_on_demo2(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);

            $metrics = AnalyticsMetrics::make($user, 30);

            foreach (DashboardWidgetCatalog::analyticsKeys() as $key) {
                $payload = $metrics->forKey($key);
                $this->assertIsArray($payload, $key.' should return an array');
                $this->assertNotSame([], array_keys($payload), $key.' should not be an empty list');
            }

            $volume = $metrics->volumeTrends();
            $this->assertArrayHasKey('days', $volume);
            $this->assertArrayHasKey('receive_total', $volume);
            $this->assertArrayHasKey('ship_total', $volume);

            $aging = $metrics->exceptionAging();
            $this->assertArrayHasKey('bands', $aging);
            $this->assertArrayHasKey('severities', $aging);

            $sla = $metrics->tracingSlaScore();
            $this->assertArrayHasKey('score_percent', $sla);
            $this->assertArrayHasKey('at_risk', $sla);

            $vrs = $metrics->vrsRates();
            $this->assertArrayHasKey('allowed', $vrs);
            $this->assertArrayHasKey('total', $vrs);

            $partners = $metrics->partnerThroughput();
            $this->assertArrayHasKey('partners', $partners);

            $integration = $metrics->integrationTrends();
            $this->assertArrayHasKey('days', $integration);
            if ($integration['days'] !== []) {
                $this->assertArrayHasKey('inbound_wip', $integration['days'][0]);
                $this->assertArrayHasKey('inbound_voided', $integration['days'][0]);
            }

            $atp = $metrics->atpExpiry();
            $this->assertArrayHasKey('within_30', $atp);
            $this->assertArrayHasKey('licenses', $atp);

            $sites = $metrics->siteComparison();
            $this->assertArrayHasKey('sites', $sites);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function operations_hub_directories_include_analytics_when_accessible(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $this->assertTrue(Analytics::canAccess());

            $labels = collect((new OperationsHub)->directories())->pluck('label')->all();
            $this->assertContains('Analytics', $labels);
        } finally {
            $this->cleanup();
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $tenant = tenant();

        if ($tenant instanceof Tenant && $this->priorJobRolesEnabled !== null) {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled($this->priorJobRolesEnabled);
            $tenant->save();
        }

        $this->priorJobRolesEnabled = null;
        tenancy()->end();
    }
}
