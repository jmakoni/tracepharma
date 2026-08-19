<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Pages\EpcisHubSettings;
use App\Models\Admin;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\PlatformSettings;
use App\Support\TenantHostname;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class EpcisHubSettingsPageTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $orphanTenantIds = [];

    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if ($this->orphanTenantIds !== []) {
            Domain::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
            Tenant::withoutEvents(fn () => Tenant::query()->whereIn('id', $this->orphanTenantIds)->delete());
            $this->orphanTenantIds = [];
        }

        PlatformSettings::forget('epcis_hub.demo.hub_token');
        PlatformSettings::forget('epcis_hub.demo.providers');
        PlatformSettings::forget('epcis_hub.demo.host');
        PlatformSettings::forget('epcis_hub.stage.hub_token');
        PlatformSettings::forget('epcis_hub.stage.providers');
        PlatformSettings::forget('epcis_hub.stage.host');
        PlatformSettings::forget('epcis_hub.prod.hub_token');
        PlatformSettings::forget('epcis_hub.prod.providers');
        PlatformSettings::forget('epcis_hub.prod.host');

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }

        parent::tearDown();
    }

    #[Test]
    public function support_cannot_access_epcis_hub_settings(): void
    {
        $support = $this->actAsAdmin(AdminRole::Support);

        $this->assertFalse($support->can(Permissions::CatalogManage));
        $this->assertFalse(EpcisHubSettings::canAccess());

        Livewire::test(EpcisHubSettings::class)->assertForbidden();
    }

    #[Test]
    public function platform_admin_can_save_epcis_hub_settings(): void
    {
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        $this->assertTrue($admin->can(Permissions::CatalogManage));
        $this->assertTrue(EpcisHubSettings::canAccess());

        Livewire::test(EpcisHubSettings::class)
            ->fillForm([
                'demo' => [
                    'hub_token' => 'demo-platform-token',
                    'providers' => ['systech', 'unitrace'],
                    'host' => '',
                ],
                'stage' => [
                    'hub_token' => 'stage-platform-token',
                    'providers' => ['systech'],
                    'host' => 'stage.test.tracepharma.io',
                ],
                'prod' => [
                    'hub_token' => 'prod-platform-token',
                    'providers' => ['systech', 'unitrace'],
                    'host' => '',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $config = app(EpcisHubPlatformConfig::class);

        $this->assertSame('demo-platform-token', $config->hubToken('demo'));
        $this->assertSame(['systech', 'unitrace'], $config->enabledProviders('demo'));
        $this->assertSame('admin2.internal.vatengi.com', $config->host('demo'));
        $this->assertSame('stage-platform-token', $config->hubToken('stage'));
        $this->assertSame(['systech'], $config->enabledProviders('stage'));
        $this->assertSame('stage.test.tracepharma.io', $config->host('stage'));
        $this->assertSame('prod-platform-token', $config->hubToken('prod'));
        $this->assertSame(['systech', 'unitrace'], $config->enabledProviders('prod'));
    }

    #[Test]
    public function host_override_rejects_existing_tenant_domains(): void
    {
        $slug = 'hub-host-conflict-'.Str::lower(Str::random(6));
        $tenantId = (string) Str::uuid();
        $this->orphanTenantIds[] = $tenantId;
        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $tenantId,
            'name' => 'Hub host conflict orphan',
            'status' => 'active',
            'tenancy_db_name' => 'tenant_hub_'.substr(str_replace('-', '', $tenantId), 0, 16),
        ]));
        $tenant->domains()->create(['domain' => TenantHostname::forSlug($slug, 'prod')]);

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(EpcisHubSettings::class)
            ->fillForm([
                'demo' => ['hub_token' => '', 'providers' => [], 'host' => ''],
                'stage' => ['hub_token' => '', 'providers' => [], 'host' => ''],
                'prod' => [
                    'hub_token' => '',
                    'providers' => [],
                    'host' => TenantHostname::forSlug($slug, 'prod'),
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['prod.host' => true]);
    }

    #[Test]
    public function host_override_rejects_tenant_pair_hostname_pattern(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $pairHost = TenantHostname::forSlug('acme-pharmacy', 'stage');

        Livewire::test(EpcisHubSettings::class)
            ->fillForm([
                'demo' => ['hub_token' => '', 'providers' => [], 'host' => ''],
                'stage' => [
                    'hub_token' => '',
                    'providers' => [],
                    'host' => $pairHost,
                ],
                'prod' => ['hub_token' => '', 'providers' => [], 'host' => ''],
            ])
            ->call('save')
            ->assertHasFormErrors(['stage.host' => true]);
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
