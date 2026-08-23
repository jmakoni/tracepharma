<?php

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Pages\VrsLookupDirectory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\IntegrationEndpointUrl;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VrsLookupDirectoryTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function page_confirms_existing_publish_and_consume_routes(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertTrue(VrsLookupDirectory::canAccess());
            $this->assertSame('vrs-directory', VrsLookupDirectory::getSlug());
            $this->assertSame('Dispense / verify', VerifyProduct::getNavigationLabel());

            $publish = IntegrationEndpointUrl::vrsResponder(self::DEMO2_TENANT_ID);
            $this->assertStringContainsString('/api/webhooks/vrs/'.self::DEMO2_TENANT_ID, $publish);

            $responder = Route::getRoutes()->getByName('webhooks.vrs.responder');
            $this->assertNotNull($responder);
            $this->assertContains('throttle:webhooks', $responder->gatherMiddleware());

            $consume = Route::getRoutes()->getByName('api.v1.dispense-check');
            $this->assertNotNull($consume);
            $this->assertContains('abilities:vrs:dispense-check', $consume->gatherMiddleware());

            Livewire::test(VrsLookupDirectory::class)
                ->assertSuccessful()
                ->assertSee('/api/v1/dispense-check', false)
                ->assertSee('/api/webhooks/vrs/', false);
        } finally {
            tenancy()->end();
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
