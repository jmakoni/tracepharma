<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EpcisReceivedVia;
use App\Enums\InboundTransport;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\IntegrationHealth;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Integrations\IntegrationHealthMetrics;
use App\Support\Integrations\OutboundTransportAvailability;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationHealthPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $inboundConnectionId = null;

    private ?int $outboundConnectionId = null;

    private ?int $httpsConnectionId = null;

    #[Test]
    public function outbound_connections_for_health_page_do_not_load_credentials(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $connection = OutboundConnection::query()->create([
                'name' => 'Secret Outbound',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
                'credentials' => ['webhook_token' => 'super-secret-token'],
                'last_error' => 'POST failed for https://user:pass@partner.example/epcis?token=abc',
            ]);
            $this->outboundConnectionId = (int) $connection->getKey();

            $loaded = app(IntegrationHealthMetrics::class)->outboundConnections()->firstWhere('id', $connection->getKey());

            $this->assertNotNull($loaded);
            $this->assertFalse($loaded->offsetExists('credentials'));
            $this->assertNull($loaded->getAttribute('credentials'));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function integration_health_page_redacts_last_error_urls_in_the_ui(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $connection = InboundConnection::query()->create([
                'name' => 'Error Redaction Inbound',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'last_error' => 'Webhook failed: https://user:secret@partner.example/hook?token=abc',
            ]);
            $this->inboundConnectionId = (int) $connection->getKey();

            $redacted = app(IntegrationHealthMetrics::class)->redactLastError($connection->last_error);

            $this->assertSame(
                'Webhook failed: https://partner.example/hook',
                $redacted,
            );

            Livewire::test(IntegrationHealth::class)
                ->assertOk()
                ->assertSee('Error Redaction Inbound')
                ->assertSee('https://partner.example/hook')
                ->assertDontSee('user:secret@partner.example')
                ->assertDontSee('token=abc');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function pharmacy_tenant_can_access_integration_health_page(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $this->assertTrue(IntegrationHealth::canAccess());

            Livewire::test(IntegrationHealth::class)
                ->assertOk()
                ->assertSee('Integration health')
                ->assertSee('Inbound EPCIS (last 24 hours)')
                ->assertSee('Success')
                ->assertSee('In flight')
                ->assertSee('Error')
                ->assertSee('Inbound connections');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function integration_health_shows_active_sftp_and_deactivate_action(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            $this->actingAs($this->createOwner(TenantProfile::DrugWholesaler));

            $connection = OutboundConnection::query()->create([
                'name' => 'Active SFTP Outbound',
                'serialization_provider' => SerializationProvider::CustomSftp,
                'transport' => OutboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'host' => 'sftp.example',
                    'outbound_path' => '/outbound/epcis',
                ],
                'credentials' => ['username' => 'sftp-user'],
            ]);
            $this->outboundConnectionId = (int) $connection->getKey();

            Livewire::test(IntegrationHealth::class)
                ->assertOk()
                ->assertSee('Active SFTP Outbound')
                ->assertDontSee('Legacy/unavailable')
                ->assertDontSee('not available in this release')
                ->assertActionVisible('deactivateLegacySftp')
                ->mountAction('deactivateLegacySftp')
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $connection->refresh();
            $this->assertFalse($connection->is_active);
            $this->assertSame(0, app(IntegrationHealthMetrics::class)->activeLegacySftpOutboundCount());

            Livewire::test(IntegrationHealth::class)
                ->assertActionDoesNotExist('deactivateLegacySftp');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function integration_viewer_without_maintainer_rights_cannot_deactivate_legacy_sftp(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            OutboundConnection::query()->create([
                'name' => 'Viewer Blocked SFTP',
                'serialization_provider' => SerializationProvider::CustomSftp,
                'transport' => OutboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'host' => 'sftp.example',
                    'outbound_path' => '/outbound/epcis',
                ],
                'credentials' => ['username' => 'sftp-user'],
            ]);
            $this->outboundConnectionId = (int) OutboundConnection::query()
                ->where('name', 'Viewer Blocked SFTP')
                ->value('id');

            $viewer = User::factory()->create();
            $viewer->assignRole(TenantRole::WmsIntegrationSpecialist->value);
            $this->actingAs($viewer);

            $this->assertTrue(IntegrationHealth::canAccess());
            $this->assertFalse(OutboundConnectionResource::canCreate());

            Livewire::test(IntegrationHealth::class)
                ->assertOk()
                ->assertSee('Viewer Blocked SFTP')
                ->assertActionDoesNotExist('deactivateLegacySftp');

            $this->assertTrue(
                OutboundConnection::query()->whereKey($this->outboundConnectionId)->value('is_active'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inactive_sftp_does_not_show_legacy_unavailable_badge(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            $this->actingAs($this->createOwner(TenantProfile::DrugWholesaler));

            OutboundConnection::query()->create([
                'name' => 'Inactive SFTP Outbound',
                'serialization_provider' => SerializationProvider::CustomSftp,
                'transport' => OutboundTransport::Sftp,
                'is_active' => false,
                'settings' => [
                    'host' => 'sftp.example',
                    'outbound_path' => '/outbound/epcis',
                ],
                'credentials' => ['username' => 'sftp-user'],
            ]);
            $this->outboundConnectionId = (int) OutboundConnection::query()
                ->where('name', 'Inactive SFTP Outbound')
                ->value('id');

            Livewire::test(IntegrationHealth::class)
                ->assertOk()
                ->assertSee('Inactive SFTP Outbound')
                ->assertDontSee('Legacy/unavailable')
                ->assertActionDoesNotExist('deactivateLegacySftp');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function deactivate_legacy_sftp_leaves_https_connections_active(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            $this->actingAs($this->createOwner(TenantProfile::DrugWholesaler));

            OutboundConnection::query()->create([
                'name' => 'SFTP To Deactivate',
                'serialization_provider' => SerializationProvider::CustomSftp,
                'transport' => OutboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'host' => 'sftp.example',
                    'outbound_path' => '/outbound/epcis',
                ],
                'credentials' => ['username' => 'sftp-user'],
            ]);
            $this->outboundConnectionId = (int) OutboundConnection::query()
                ->where('name', 'SFTP To Deactivate')
                ->value('id');

            $https = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->httpsConnectionId = (int) $https->getKey();

            $count = OutboundTransportAvailability::deactivateActiveLegacySftpConnections();

            $this->assertSame(1, $count);
            $this->assertTrue($https->fresh()->is_active);
            $this->assertFalse(
                OutboundConnection::query()->whereKey($this->outboundConnectionId)->value('is_active'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_stats_reflect_document_received_in_last_24_hours(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $component = Livewire::test(IntegrationHealth::class)->assertOk();
            $before = $component->instance()->inboundStats();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'received_via' => EpcisReceivedVia::FilamentUpload,
                'creation_date' => now()->subHour(),
                'received_at' => now()->subHour(),
                'status' => 'validated',
                'sender_gln' => '0301160000009',
                'receiver_gln' => '0096295000009',
            ]);
            $this->documentId = (int) $document->getKey();

            $after = Livewire::test(IntegrationHealth::class)->assertOk()->instance()->inboundStats();
            $this->assertSame($before['success'] + 1, $after['success']);
            $this->assertSame($before['total'] + 1, $after['total']);
        } finally {
            $this->cleanup();
        }
    }

    private function createOwner(?TenantProfile $profile = null): User
    {
        $profile ??= TenantProfile::Pharmacy;
        app(TenantRoleSeeder::class)->seedForProfile($profile);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function initializeDemo2Tenant(?TenantProfile $profile = null): Tenant
    {
        $profile ??= TenantProfile::Pharmacy;
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            if ($tenant->profile !== $profile) {
                $tenant->forceFill(['profile' => $profile])->save();
            }
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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
        }

        if ($this->inboundConnectionId !== null) {
            InboundConnection::query()->whereKey($this->inboundConnectionId)->delete();
        }

        if ($this->outboundConnectionId !== null) {
            OutboundConnection::query()->whereKey($this->outboundConnectionId)->delete();
        }

        if ($this->httpsConnectionId !== null) {
            OutboundConnection::query()->whereKey($this->httpsConnectionId)->delete();
        }

        $this->documentId = null;
        $this->inboundConnectionId = null;
        $this->outboundConnectionId = null;
        $this->httpsConnectionId = null;

        tenancy()->end();
    }
}
