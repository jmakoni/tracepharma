<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SanctumAbilities;
use App\Support\TenantSettings;
use App\Support\TenantHostname;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class SuspendedTenantAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const RESPONDER_KEY = 'test-vrs-responder-suspend-key';

    /** @var list<string> */
    private array $slugs = [];

    /** @var list<string> */
    private array $orphanTenantIds = [];

    protected function tearDown(): void
    {
        foreach ($this->slugs as $slug) {
            $this->destroyPair($slug);
        }

        $demo2 = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($demo2 !== null) {
            $demo2->update(['status' => 'active']);
        }

        if ($this->orphanTenantIds !== []) {
            Domain::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
            Tenant::withoutEvents(fn () => Tenant::query()->whereIn('id', $this->orphanTenantIds)->delete());
        }

        tenancy()->end();

        parent::tearDown();
    }

    #[Test]
    public function can_access_panel_returns_false_when_tenant_is_suspended(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);

        $user = User::factory()->create();
        $panel = Filament::getPanel('app');

        $this->assertTrue($user->canAccessPanel($panel));

        tenancy()->end();
        $tenant->update(['status' => 'suspended']);
        tenancy()->initialize($tenant->fresh());

        $this->assertFalse($user->canAccessPanel($panel));
    }

    #[Test]
    public function vrs_responder_webhook_returns_forbidden_when_tenant_is_suspended(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        TenantSettings::forTenant($tenant)->setVrsResponderApiKey(self::RESPONDER_KEY);
        $tenant->save();

        $tenant->update(['status' => 'suspended']);
        tenancy()->end();

        $this->postJson(
            '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
            [
                'gtin14' => '30301164005162',
                'serial' => 'SUSPENDED-'.random_int(1000, 9999),
            ],
            ['X-Vrs-Api-Key' => self::RESPONDER_KEY],
        )->assertForbidden();
    }

    #[Test]
    public function tenant_databases_isolate_product_data(): void
    {
        $slugA = 'iso-a-'.Str::lower(Str::random(6));
        $slugB = 'iso-b-'.Str::lower(Str::random(6));
        $this->slugs[] = $slugA;
        $this->slugs[] = $slugB;

        $tenantA = app(ProvisionTenantPair::class)->create($slugA, [
            'name' => 'Isolation A '.$slugA,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $tenantB = app(ProvisionTenantPair::class)->create($slugB, [
            'name' => 'Isolation B '.$slugB,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $this->assertNotSame($tenantA->tenancy_db_name, $tenantB->tenancy_db_name);

        $uniqueGtin = '5555'.random_int(1000000000, 9999999999);

        $productId = $tenantA->run(function () use ($uniqueGtin): int {
            $product = Product::factory()->create(['gtin' => $uniqueGtin]);

            return (int) $product->getKey();
        });

        $tenantB->run(function () use ($uniqueGtin, $productId): void {
            $this->assertFalse(Product::query()->where('gtin', $uniqueGtin)->exists());
            $this->assertFalse(Product::query()->whereKey($productId)->exists());
        });
    }

    #[Test]
    public function suspended_tenant_app_web_request_returns_forbidden_not_server_error(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        $tenant->update(['status' => 'suspended']);
        tenancy()->end();

        $this->get('http://'.self::DEMO2_DOMAIN.'/', [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
        ])
            ->assertForbidden()
            ->assertSee('This organization account is suspended.');
    }

    #[Test]
    public function sanctum_api_returns_forbidden_when_tenant_is_suspended(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);

        config(['vrs.driver' => 'fake']);

        $user = User::factory()->create();
        $token = $user->createToken('suspend-test', [SanctumAbilities::VRS_DISPENSE_CHECK])->plainTextToken;

        tenancy()->end();
        $tenant->update(['status' => 'suspended']);

        $this->tenantApiPost('/api/v1/dispense-check', $token, [
            'gtin14' => '30301164005162',
            'serial' => 'SUSPENDED-'.random_int(1000, 9999),
        ])
            ->assertForbidden()
            ->assertJson([
                'message' => 'This organization account is suspended.',
            ]);
    }

    #[Test]
    public function suspending_prod_cascades_status_to_stage_sibling(): void
    {
        $slug = 'ssor-suspend-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        $prod = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Suspend Cascade '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        $stage = app(ProvisionTenantOnEnvironment::class)->findBySlugAndEnvironment($slug, 'stage');
        $this->assertNotNull($stage);
        $this->assertSame('active', $prod->fresh()->status);
        $this->assertSame('active', $stage->fresh()->status);

        $admin = $this->actAsPlatformAdmin();

        Livewire::test(EditTenant::class, ['record' => $prod->getKey()])
            ->fillForm(['status' => 'suspended'])
            ->call('save')
            ->assertHasNoFormErrors();

        unset($admin);

        $this->assertSame('suspended', $prod->fresh()->status);
        $this->assertSame('suspended', $stage->fresh()->status);
    }

    private function ensureDemo2Tenant(): Tenant
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
            $this->orphanTenantIds[] = self::DEMO2_TENANT_ID;
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            $tenant->update(['status' => 'active']);
        }

        return $tenant->fresh();
    }

    private function actAsPlatformAdmin(): \App\Models\Admin
    {
        app(\App\Support\Auth\AdminRoleSeeder::class)->seed();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = \App\Models\Admin::factory()->create();
        $admin->assignRole(\App\Enums\AdminRole::PlatformAdmin->value);

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tenantApiPost(string $uri, string $token, array $data = []): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            [],
            [
                'HTTP_HOST' => self::DEMO2_DOMAIN,
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function destroyPair(string $slug): void
    {
        foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
            $domain = Domain::query()
                ->where('domain', TenantHostname::forSlug($slug, $environment))
                ->first();

            if ($domain === null) {
                continue;
            }

            Tenant::query()->find($domain->tenant_id)?->delete();
        }
    }
}
