<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Pages\PlatformConnections;
use App\Models\Admin;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\PlatformAs2Station;
use App\Support\Integrations\PlatformSftpConfig;
use App\Support\PlatformSettings;
use App\Support\TenantHostname;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class PlatformConnectionsPageTest extends TestCase
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

        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            foreach ([
                'hub_token',
                'hub_token_previous',
                'hub_token_previous_expires_at',
                'providers',
                'host',
                'outbound_url_systech',
                'outbound_token_systech',
                'outbound_url_unitrace',
                'outbound_token_unitrace',
            ] as $key) {
                PlatformSettings::forget("epcis_hub.{$environment}.{$key}");
            }

            foreach (['station_id', 'signing_cert_pem', 'signing_key_pem', 'decrypt_cert_pem', 'decrypt_key_pem', 'senders'] as $key) {
                PlatformSettings::forget("platform_as2.{$environment}.{$key}");
            }

            foreach (['host', 'port', 'username', 'password', 'private_key', 'passphrase', 'inbound_path', 'processed_path', 'outbound_path'] as $key) {
                PlatformSettings::forget("platform_sftp.{$environment}.{$key}");
            }
        }

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
    public function support_cannot_access_platform_connections(): void
    {
        $support = $this->actAsAdmin(AdminRole::Support);

        $this->assertFalse($support->can(Permissions::CatalogManage));
        $this->assertFalse(PlatformConnections::canAccess());

        Livewire::test(PlatformConnections::class)->assertForbidden();
    }

    #[Test]
    public function platform_admin_can_save_hub_settings(): void
    {
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        $this->assertTrue($admin->can(Permissions::CatalogManage));
        $this->assertTrue(PlatformConnections::canAccess());

        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'demo' => [
                    'hub_token' => 'demo-platform-token',
                    'provider_tracepharma' => false,
                    'providers_external' => ['systech', 'unitrace'],
                    'host' => '',
                ],
                'stage' => [
                    'hub_token' => 'stage-platform-token',
                    'provider_tracepharma' => false,
                    'providers_external' => ['systech'],
                    'host' => 'stage.test.tracepharma.io',
                ],
                'prod' => [
                    'hub_token' => 'prod-platform-token',
                    'provider_tracepharma' => true,
                    'providers_external' => ['systech', 'unitrace'],
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
        $this->assertSame(['systech', 'unitrace', 'tracepharma'], $config->enabledProviders('prod'));
    }

    #[Test]
    public function platform_admin_can_save_outbound_edge_settings(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'demo' => [
                    'outbound_url_systech' => 'https://hub.systech.example/api/webhooks/epcis/hub/systech',
                    'outbound_token_systech' => 'systech-edge-token',
                    'outbound_url_unitrace' => 'https://hub.unitrace.example/api/webhooks/epcis/hub/unitrace',
                    'outbound_token_unitrace' => 'unitrace-edge-token',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $config = app(EpcisHubPlatformConfig::class);

        $this->assertSame('https://hub.systech.example/api/webhooks/epcis/hub/systech', $config->outboundUrl('demo', 'systech'));
        $this->assertSame('systech-edge-token', $config->outboundToken('demo', 'systech'));
        $this->assertSame('unitrace-edge-token', $config->outboundToken('demo', 'unitrace'));
        $this->assertTrue($config->hasOutboundEdge('demo', 'systech'));

        // Blank token on a later save keeps the stored token; blank URL clears it.
        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'demo' => [
                    'outbound_url_unitrace' => '',
                    'outbound_token_systech' => '',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('systech-edge-token', $config->outboundToken('demo', 'systech'));
        $this->assertNull($config->outboundUrl('demo', 'unitrace'));
    }

    #[Test]
    public function platform_admin_can_save_as2_station_and_sender_registry(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'demo' => [
                    'as2_station_id' => 'TRACEPHARMA-DEMO',
                    'as2_decrypt_cert_pem' => "-----BEGIN CERTIFICATE-----\ndecrypt-cert\n-----END CERTIFICATE-----",
                    'as2_decrypt_key_pem' => "-----BEGIN PRIVATE KEY-----\ndecrypt-key\n-----END PRIVATE KEY-----",
                    'as2_signing_cert_pem' => "-----BEGIN CERTIFICATE-----\nsigning-cert\n-----END CERTIFICATE-----",
                    'as2_signing_key_pem' => "-----BEGIN PRIVATE KEY-----\nsigning-key\n-----END PRIVATE KEY-----",
                    'as2_senders' => [
                        ['label' => 'Acme QA', 'as2_id' => 'ACME-TEST', 'signing_cert_pem' => 'acme-cert'],
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $station = app(PlatformAs2Station::class);

        $this->assertSame('TRACEPHARMA-DEMO', $station->stationId('demo'));
        $this->assertTrue($station->isConfigured('demo'));
        $this->assertStringContainsString('signing-cert', (string) $station->signingCertPem('demo'));
        $this->assertSame('acme-cert', $station->senderCertificate('demo', 'ACME-TEST'));

        // PEM fields are write-only: a later save with blanks keeps stored values.
        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'demo' => [
                    'as2_station_id' => 'TRACEPHARMA-DEMO',
                    'as2_senders' => [],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringContainsString('signing-key', (string) $station->signingKeyPem('demo'));
        $this->assertSame([], $station->senders('demo'));
    }

    #[Test]
    public function platform_admin_can_save_sftp_settings(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'stage' => [
                    'sftp_host' => 'sftp.tracepharma.io',
                    'sftp_port' => '2222',
                    'sftp_username' => 'tracepharma',
                    'sftp_password' => 's3cret',
                    'sftp_inbound_path' => '/drops/inbound',
                    'sftp_processed_path' => '/drops/processed',
                    'sftp_outbound_path' => '/drops/outbound',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $sftp = app(PlatformSftpConfig::class);

        $this->assertTrue($sftp->isConfigured('stage'));
        $this->assertSame('sftp.tracepharma.io', $sftp->host('stage'));
        $this->assertSame(2222, $sftp->port('stage'));
        $this->assertSame('s3cret', $sftp->password('stage'));
        $this->assertSame('/drops/inbound', $sftp->inboundPath('stage'));

        // Secrets are write-only: blank on a later save keeps the password.
        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'stage' => [
                    'sftp_host' => 'sftp.tracepharma.io',
                    'sftp_port' => '2222',
                    'sftp_username' => 'tracepharma',
                    'sftp_password' => '',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('s3cret', $sftp->password('stage'));
    }

    #[Test]
    public function generate_token_rotates_and_reveals_the_new_token_once(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $config = app(EpcisHubPlatformConfig::class);
        $config->setHubToken('demo', 'current-demo-token');

        $component = Livewire::test(PlatformConnections::class);

        $component
            ->mountAction(TestAction::make('generateToken_demo')->schemaComponent('demo_inbound_hub', 'form'))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $newToken = $config->hubToken('demo');

        $this->assertNotSame('current-demo-token', $newToken);
        $this->assertSame('current-demo-token', $config->previousHubToken('demo'));
        $this->assertSame($newToken, $component->get('data')['demo_generated_token'] ?? null);
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

        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'demo' => ['hub_token' => '', 'provider_tracepharma' => false, 'providers_external' => [], 'host' => ''],
                'stage' => ['hub_token' => '', 'provider_tracepharma' => false, 'providers_external' => [], 'host' => ''],
                'prod' => [
                    'hub_token' => '',
                    'provider_tracepharma' => false,
                    'providers_external' => [],
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

        Livewire::test(PlatformConnections::class)
            ->fillForm([
                'demo' => ['hub_token' => '', 'provider_tracepharma' => false, 'providers_external' => [], 'host' => ''],
                'stage' => [
                    'hub_token' => '',
                    'provider_tracepharma' => false,
                    'providers_external' => [],
                    'host' => $pairHost,
                ],
                'prod' => ['hub_token' => '', 'provider_tracepharma' => false, 'providers_external' => [], 'host' => ''],
            ])
            ->call('save')
            ->assertHasFormErrors(['stage.host' => true]);
    }

    #[Test]
    public function aggregation_fk_doctor_warns_when_detect_only_finds_drift(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $component = Livewire::test(PlatformConnections::class)->assertSuccessful();

        Artisan::shouldReceive('call')
            ->once()
            ->with('tracepharma:doctor-aggregation-link-fk')
            ->andReturn(1);
        Artisan::shouldReceive('output')->andReturn('[tenant] CASCADE drift');

        $component
            ->mountAction('runAggregationLinkFkDoctor')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Aggregation link FK doctor found drift');
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
