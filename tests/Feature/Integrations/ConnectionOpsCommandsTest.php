<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RegisterConnectionApprovalRequest;
use App\Enums\AdminRole;
use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\PlatformSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConnectionOpsCommandsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $inboundConnectionIds = [];

    /** @var list<int> */
    private array $outboundConnectionIds = [];

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $routeIds = [];

    /** @var list<int> */
    private array $adminIds = [];

    /** @var array{inbound_environment: mixed, hub_providers: mixed}|null */
    private ?array $originalEntitlement = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        $this->restoreEntitlement();
        $this->forgetHubSettings();

        parent::tearDown();
    }

    #[Test]
    public function connections_list_filters_by_direction_and_status(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $inbound = $this->makeInboundConnection();
            $request = app(RegisterConnectionApprovalRequest::class)->register($inbound);
            $this->requestIds[] = (int) $request->getKey();

            tenancy()->end();

            $this->artisan('connections:list', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--direction' => 'inbound',
                '--status' => 'pending',
            ])
                ->expectsOutputToContain((string) $request->getKey())
                ->assertSuccessful();

            $this->artisan('connections:list', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--direction' => 'outbound',
                '--status' => 'pending',
            ])
                ->expectsOutputToContain('No connection requests matched.')
                ->assertSuccessful();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function connections_review_approves_and_rejects_with_note(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $admin = $this->createAdmin();

            $approveConnection = $this->makeInboundConnection();
            $approveRequest = app(RegisterConnectionApprovalRequest::class)->register($approveConnection);
            $this->requestIds[] = (int) $approveRequest->getKey();

            $rejectConnection = $this->makeInboundConnection();
            $rejectRequest = app(RegisterConnectionApprovalRequest::class)->register($rejectConnection);
            $this->requestIds[] = (int) $rejectRequest->getKey();

            tenancy()->end();

            $this->artisan('connections:review', [
                'request' => $approveRequest->getKey(),
                '--approve' => true,
                '--admin' => $admin->getKey(),
            ])->assertSuccessful();

            $this->artisan('connections:review', [
                'request' => $rejectRequest->getKey(),
                '--reject' => true,
                '--note' => 'Missing sender GLN.',
                '--admin' => $admin->getKey(),
            ])->assertSuccessful();

            $this->assertSame(ConnectionApprovalStatus::Approved, $approveRequest->fresh()->status);
            $this->assertSame(ConnectionApprovalStatus::Rejected, $rejectRequest->fresh()->status);
            $this->assertSame('Missing sender GLN.', $rejectRequest->fresh()->review_note);

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertSame(ConnectionApprovalStatus::Approved, $approveConnection->fresh()->approval_status);
            $this->assertSame(ConnectionApprovalStatus::Rejected, $rejectConnection->fresh()->approval_status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function connections_review_requires_exactly_one_decision_and_a_note_on_reject(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = $this->makeInboundConnection();
            $request = app(RegisterConnectionApprovalRequest::class)->register($connection);
            $this->requestIds[] = (int) $request->getKey();

            tenancy()->end();

            $this->artisan('connections:review', ['request' => $request->getKey()])->assertFailed();
            $this->artisan('connections:review', [
                'request' => $request->getKey(),
                '--reject' => true,
            ])->assertFailed();

            $this->assertSame(ConnectionApprovalStatus::Pending, $request->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function connections_suspend_and_resume_toggle_active_with_an_audit_trail(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = $this->makeInboundConnection();
            $connectionId = (int) $connection->getKey();

            tenancy()->end();

            $this->artisan('connections:suspend', [
                'tenant' => self::DEMO2_TENANT_ID,
                'connection' => $connectionId,
                '--direction' => 'inbound',
                '--reason' => 'Rogue traffic spike',
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertFalse((bool) $connection->fresh()->is_active);

            $log = Activity::query()
                ->where('subject_type', InboundConnection::class)
                ->where('subject_id', $connectionId)
                ->where('description', 'connection suspended via CLI')
                ->latest('id')
                ->first();

            $this->assertNotNull($log);
            $this->assertSame('Rogue traffic spike', $log->properties->get('reason'));

            tenancy()->end();

            $this->artisan('connections:resume', [
                'tenant' => self::DEMO2_TENANT_ID,
                'connection' => $connectionId,
                '--direction' => 'inbound',
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertTrue((bool) $connection->fresh()->is_active);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function hub_provider_commands_manage_enabled_providers(): void
    {
        $this->artisan('hub:enable-provider', [
            'environment' => 'demo',
            'provider' => 'opsnet',
        ])->assertSuccessful();

        $this->assertContains('opsnet', app(EpcisHubPlatformConfig::class)->enabledProviders('demo'));

        $this->artisan('hub:providers', ['environment' => 'demo'])
            ->expectsOutputToContain('opsnet')
            ->assertSuccessful();

        $this->artisan('hub:disable-provider', [
            'environment' => 'demo',
            'provider' => 'opsnet',
        ])->assertSuccessful();

        $this->assertNotContains('opsnet', app(EpcisHubPlatformConfig::class)->enabledProviders('demo'));

        $this->artisan('hub:enable-provider', [
            'environment' => 'mars',
            'provider' => 'opsnet',
        ])->assertFailed();
    }

    #[Test]
    public function hub_rotate_token_rotates_with_grace(): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $before = $config->hubToken('demo');

        $this->artisan('hub:rotate-token', [
            'environment' => 'demo',
            '--show' => true,
        ])->assertSuccessful();

        $after = $config->hubToken('demo');

        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
        $this->assertSame($before, $config->previousHubToken('demo'));
    }

    #[Test]
    public function hub_register_and_unregister_route_manage_admin_claims(): void
    {
        $this->initializeDemo2Tenant();
        $this->entitleDemo2(['tracepharma']);
        $this->enablePlatformProviders('demo', ['tracepharma']);

        try {
            tenancy()->end();

            $this->artisan('hub:register-route', [
                'tenant' => self::DEMO2_TENANT_ID,
                'provider' => 'tracepharma',
                'gln' => '0300001000017',
            ])->assertFailed();

            $this->artisan('hub:register-route', [
                'tenant' => self::DEMO2_TENANT_ID,
                'provider' => 'tracepharma',
                'gln' => '0300001000017',
                '--allow-orphan' => true,
            ])->assertSuccessful();

            $route = EpcisHubRoute::query()
                ->where('tenant_id', self::DEMO2_TENANT_ID)
                ->where('provider', 'tracepharma')
                ->where('gln', '0300001000017')
                ->first();

            $this->assertNotNull($route);
            $this->routeIds[] = (int) $route->getKey();

            $this->artisan('hub:routes', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--provider' => 'tracepharma',
            ])
                ->expectsOutputToContain('0300001000017')
                ->assertSuccessful();

            $this->artisan('hub:unregister-route', [
                'tenant' => self::DEMO2_TENANT_ID,
                'provider' => 'tracepharma',
                'gln' => '0300001000017',
            ])->assertSuccessful();

            $this->assertNull($route->fresh());
            $this->routeIds = [];
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function tenant_entitle_sets_environment_and_providers(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->captureEntitlement($tenant);

        tenancy()->end();

        $this->artisan('tenant:entitle', [
            'tenant' => self::DEMO2_TENANT_ID,
            '--inbound-env' => 'demo',
            '--add-provider' => 'tracepharma,unitrace',
        ])->assertSuccessful();

        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);

        $this->assertSame('demo', $tenant->inbound_environment);
        $this->assertContains('tracepharma', $tenant->hub_providers);
        $this->assertContains('unitrace', $tenant->hub_providers);

        $this->artisan('tenant:entitle', [
            'tenant' => self::DEMO2_TENANT_ID,
            '--remove-provider' => 'unitrace',
        ])->assertSuccessful();

        $this->assertNotContains('unitrace', Tenant::query()->findOrFail(self::DEMO2_TENANT_ID)->hub_providers);

        $this->artisan('tenant:entitle', [
            'tenant' => self::DEMO2_TENANT_ID,
            '--inbound-env' => 'mars',
        ])->assertFailed();
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

        return $tenant;
    }

    private function makeInboundConnection(): InboundConnection
    {
        $connection = InboundConnection::query()->create([
            'name' => 'Ops CLI '.Str::random(6),
            'serialization_provider' => SerializationProvider::TracePharma,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]);

        $this->inboundConnectionIds[] = (int) $connection->getKey();

        return $connection;
    }

    private function createAdmin(): Admin
    {
        $resume = tenancy()->initialized ? tenant() : null;

        if ($resume !== null) {
            tenancy()->end();
        }

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        if ($resume !== null) {
            tenancy()->initialize($resume);
        }

        return $admin;
    }

    /**
     * @param  list<string>  $providers
     */
    private function entitleDemo2(array $providers): void
    {
        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);
        $this->captureEntitlement($tenant);

        $tenant->forceFill([
            'inbound_environment' => 'demo',
            'hub_providers' => $providers,
        ])->save();
    }

    private function captureEntitlement(Tenant $tenant): void
    {
        if ($this->originalEntitlement !== null) {
            return;
        }

        $this->originalEntitlement = [
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];
    }

    private function restoreEntitlement(): void
    {
        if ($this->originalEntitlement === null) {
            return;
        }

        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant !== null) {
            $tenant->forceFill($this->originalEntitlement)->save();
        }

        $this->originalEntitlement = null;
    }

    /**
     * @param  list<string>  $providers
     */
    private function enablePlatformProviders(string $environment, array $providers): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $enabled = $config->enabledProviders($environment);

        foreach ($providers as $provider) {
            if (! in_array($provider, $enabled, true)) {
                $enabled[] = $provider;
            }
        }

        $config->setProviders($environment, $enabled);
    }

    private function forgetHubSettings(): void
    {
        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            PlatformSettings::forget("epcis_hub.{$environment}.providers");
            PlatformSettings::forget("epcis_hub.{$environment}.hub_token");
            PlatformSettings::forget("epcis_hub.{$environment}.hub_token_previous");
            PlatformSettings::forget("epcis_hub.{$environment}.hub_token_previous_expires_at");
        }
    }

    private function cleanup(): void
    {
        $hasTenantArtifacts = $this->inboundConnectionIds !== [] || $this->outboundConnectionIds !== [];

        if ($hasTenantArtifacts && ! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            if ($this->inboundConnectionIds !== []) {
                InboundConnection::query()->whereIn('id', $this->inboundConnectionIds)->delete();
                $this->inboundConnectionIds = [];
            }

            if ($this->outboundConnectionIds !== []) {
                OutboundConnection::query()->whereIn('id', $this->outboundConnectionIds)->delete();
                $this->outboundConnectionIds = [];
            }

            tenancy()->end();
        }

        if ($this->requestIds !== []) {
            ConnectionApprovalRequest::query()->whereIn('id', $this->requestIds)->delete();
            $this->requestIds = [];
        }

        if ($this->routeIds !== []) {
            EpcisHubRoute::query()->whereIn('id', $this->routeIds)->delete();
            $this->routeIds = [];
        }

        if ($this->adminIds !== []) {
            Admin::query()->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }
    }
}
