<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Enums\ConnectionApprovalStatus;
use App\Enums\EpcisReceivedVia;
use App\Enums\InboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Jobs\PollPlatformSftpInbound;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Integrations\InboundEpcisReceiver;
use App\Services\Integrations\InboundPayloadResolver;
use App\Services\Integrations\PlatformSftpInboundPoller;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\PlatformSftpConfig;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class PlatformSftpInboundPollerTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEMO2_GLN = '0366159000010';

    private static bool $demo2TenantReady = false;

    private ?string $tempRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['tracepharma']);
    }

    protected function tearDown(): void
    {
        PlatformSettings::forget('epcis_hub.stage.providers');

        foreach (['host', 'port', 'username', 'password', 'private_key', 'passphrase', 'inbound_path', 'processed_path', 'outbound_path'] as $key) {
            PlatformSettings::forget("platform_sftp.stage.{$key}");
        }

        if ($this->tempRoot !== null && is_dir($this->tempRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tempRoot);
        }

        parent::tearDown();
    }

    #[Test]
    public function routed_file_lands_on_the_approved_tenant_connection(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = $this->configureTenantForHub($tenant);

        try {
            $connection = $this->registerHubConnection($tenant);
            $filesystem = $this->filesystemWithInboundFile('routed.xml', $this->routedFixtureXml());

            $processed = $this->poller()->poll('stage', $filesystem);

            $this->assertSame(1, $processed);
            $this->assertFalse($filesystem->fileExists('inbox/routed.xml'));
            $this->assertTrue($filesystem->fileExists('processed/routed.xml'));

            $tenant->run(function () use ($connection): void {
                $this->assertDatabaseHas('epcis_documents', [
                    'inbound_connection_id' => $connection->id,
                    'received_via' => EpcisReceivedVia::SftpHubPoll->value,
                ]);
            });

            $documentId = $tenant->run(fn () => (int) \App\Models\Epcis\EpcisDocument::query()
                ->where('inbound_connection_id', $connection->id)
                ->latest('id')
                ->value('id'));
            $this->trackEpcisDocumentId($documentId);
        } finally {
            $this->restoreTenant($tenant, $original);
            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());
            tenancy()->end();
            Mockery::close();
        }
    }

    #[Test]
    public function unrouted_file_moves_to_failed(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = $this->configureTenantForHub($tenant);

        try {
            $this->registerHubConnection($tenant);

            // Original receiver GLN (0096295000009) has no hub route.
            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);

            $filesystem = $this->filesystemWithInboundFile('orphan.xml', $xml);

            $processed = $this->poller()->poll('stage', $filesystem);

            $this->assertSame(0, $processed);
            $this->assertFalse($filesystem->fileExists('inbox/orphan.xml'));
            $this->assertTrue($filesystem->fileExists('failed/orphan.xml'));
        } finally {
            $this->restoreTenant($tenant, $original);
            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());
            tenancy()->end();
            Mockery::close();
        }
    }

    #[Test]
    public function pending_connection_is_not_routed(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = $this->configureTenantForHub($tenant);

        try {
            $connection = $this->registerHubConnection($tenant);

            // Flip to pending after registration: the router must skip it.
            $tenant->run(function () use ($connection): void {
                $connection->update(['approval_status' => ConnectionApprovalStatus::Pending->value]);
            });

            $filesystem = $this->filesystemWithInboundFile('gated.xml', $this->routedFixtureXml());

            $processed = $this->poller()->poll('stage', $filesystem);

            $this->assertSame(0, $processed);
            $this->assertTrue($filesystem->fileExists('failed/gated.xml'));

            $tenant->run(function () use ($connection): void {
                $this->assertDatabaseMissing('epcis_documents', [
                    'inbound_connection_id' => $connection->id,
                ]);
            });
        } finally {
            $this->restoreTenant($tenant, $original);
            $tenant->run(fn () => $this->cleanupTrackedEpcisArtifacts());
            tenancy()->end();
            Mockery::close();
        }
    }

    #[Test]
    public function command_dispatches_a_job_per_configured_environment(): void
    {
        Queue::fake();

        $sftp = app(PlatformSftpConfig::class);
        $sftp->save('stage', [
            'host' => 'sftp.tracepharma.io',
            'username' => 'tracepharma',
            'password' => 'secret',
        ]);

        $this->artisan('epcis:poll-platform-sftp')->assertSuccessful();

        Queue::assertPushed(PollPlatformSftpInbound::class, 1);
        Queue::assertPushed(PollPlatformSftpInbound::class, fn (PollPlatformSftpInbound $job): bool => $job->environment === 'stage');
    }

    private function poller(): PlatformSftpInboundPoller
    {
        $config = Mockery::mock(PlatformSftpConfig::class);
        $config->shouldReceive('inboundPath')->with('stage')->andReturn('inbox');
        $config->shouldReceive('processedPath')->with('stage')->andReturn('processed');

        return new PlatformSftpInboundPoller(
            $config,
            app(\App\Services\Epcis\Hub\EpcisHubRouter::class),
            app(InboundEpcisReceiver::class),
            app(InboundPayloadResolver::class),
        );
    }

    private function filesystemWithInboundFile(string $filename, string $content): Filesystem
    {
        $this->tempRoot = sys_get_temp_dir().'/platform_sftp_'.uniqid('', true);
        mkdir($this->tempRoot.'/inbox', 0777, true);
        mkdir($this->tempRoot.'/processed', 0777, true);
        mkdir($this->tempRoot.'/failed', 0777, true);
        file_put_contents($this->tempRoot.'/inbox/'.$filename, $content);

        return new Filesystem(new LocalFilesystemAdapter($this->tempRoot));
    }

    private function routedFixtureXml(): string
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        $xml = str_replace('0096295000009', self::DEMO2_GLN, $xml);

        return str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
    }

    /**
     * @return array{gln: mixed, inbound_environment: mixed, hub_providers: mixed}
     */
    private function configureTenantForHub(Tenant $tenant): array
    {
        $original = [
            'gln' => $tenant->gln,
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['tracepharma'],
        ])->save();

        return $original;
    }

    /**
     * @param  array{gln: mixed, inbound_environment: mixed, hub_providers: mixed}  $original
     */
    private function restoreTenant(Tenant $tenant, array $original): void
    {
        $tenant->forceFill($original)->save();

        EpcisHubRoute::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'tracepharma')
            ->delete();
    }

    private function registerHubConnection(Tenant $tenant): InboundConnection
    {
        $connection = $tenant->run(function (): InboundConnection {
            $partner = TradingPartner::query()->firstOrCreate(
                ['gln' => '0301160000009'],
                [
                    'name' => 'Platform SFTP fixture sender',
                    'partner_type' => PartnerType::Wholesaler,
                    'country_code' => 'US',
                    'is_active' => true,
                ],
            );

            return InboundConnection::query()->create([
                'name' => 'TracePharma SFTP Hub Test',
                'serialization_provider' => SerializationProvider::TracePharma,
                'transport' => InboundTransport::Https,
                'trading_partner_id' => $partner->id,
                'is_active' => true,
            ]);
        });
        $this->trackInboundConnectionId((int) $connection->id);

        $tenant->run(function () use ($connection): void {
            app(RegisterEpcisHubRoute::class)->register($connection);
        });

        tenancy()->end();

        // Stage/prod use the database cache store, which cannot tag. Stancl
        // wraps Cache::__call with tags() under tenancy — reproduce that here
        // after hub registration (which also touches cache).
        config(['cache.default' => 'database']);
        Cache::clearResolvedInstances();
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');

        return $connection;
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
                'gln' => self::DEMO2_GLN,
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
}
