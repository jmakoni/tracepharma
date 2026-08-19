<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\InboundTransport;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class IntegrationConnectionResourceAuthorizationTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function non_owner_cannot_create_or_edit_inbound_connections(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->actingAs($owner);

            $connection = InboundConnection::query()->create([
                'name' => 'Policy Test Inbound',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->trackInboundConnectionId((int) $connection->getKey());

            $technician = User::factory()->create();
            $technician->assignRole(TenantRole::ReceivingTechnician->value);
            $this->actingAs($technician);

            $this->assertFalse(InboundConnectionResource::canCreate());
            $this->assertFalse(InboundConnectionResource::canEdit($connection));
            $this->assertFalse(InboundConnectionResource::canDelete($connection));
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function non_owner_cannot_create_or_edit_outbound_connections_on_wholesaler_tenant(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $originalProfile = $tenant->profile;
        $tenant->setAttribute('profile', TenantProfile::DrugWholesaler);
        $tenant->save();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->actingAs($owner);

            $connection = OutboundConnection::query()->create([
                'name' => 'Policy Test Outbound',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->trackOutboundConnectionId((int) $connection->getKey());

            $technician = User::factory()->create();
            $technician->assignRole(TenantRole::ReceivingTechnician->value);
            $this->actingAs($technician);

            $this->assertFalse(OutboundConnectionResource::canCreate());
            $this->assertFalse(OutboundConnectionResource::canEdit($connection));
            $this->assertFalse(OutboundConnectionResource::canDelete($connection));
        } finally {
            $tenant->setAttribute('profile', $originalProfile);
            $tenant->save();
            $this->cleanupTrackedEpcisArtifacts();
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

        return $tenant;
    }
}
