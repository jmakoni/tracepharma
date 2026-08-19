<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Services\Integrations\InboundConnectionLogger;
use App\Services\Integrations\InboundEpcisReceiver;
use App\Services\Integrations\InboundPayloadResolver;
use App\Services\Integrations\SftpInboundReceiver;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class SftpInboundDuplicateContinuesTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?string $tempRoot = null;

    protected function tearDown(): void
    {
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
    public function poll_skips_duplicate_and_continues_remaining_files(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->tempRoot = sys_get_temp_dir().'/sftp_dup_'.uniqid('', true);
            mkdir($this->tempRoot.'/inbox', 0777, true);
            mkdir($this->tempRoot.'/processed', 0777, true);
            file_put_contents($this->tempRoot.'/inbox/dup.xml', '<epcis>dup</epcis>');
            file_put_contents($this->tempRoot.'/inbox/ok.xml', '<epcis>ok</epcis>');

            $connection = InboundConnection::query()->create([
                'name' => 'SFTP duplicate continue',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'inbound_path' => 'inbox',
                    'processed_path' => 'processed',
                    'processed_files' => [],
                ],
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $existing = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'parsed',
                'received_at' => now(),
            ]);
            $this->trackEpcisDocumentId((int) $existing->id);

            $okDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'received',
                'received_at' => now(),
            ]);
            $this->trackEpcisDocumentId((int) $okDocument->id);

            $receiver = Mockery::mock(InboundEpcisReceiver::class);
            $receiver->shouldReceive('receive')
                ->twice()
                ->andReturnUsing(function () use ($existing, $okDocument) {
                    static $calls = 0;
                    $calls++;

                    if ($calls === 1) {
                        throw new DuplicateEpcisUploadException($existing);
                    }

                    return [
                        'document' => $okDocument,
                        'trading_partner_id' => null,
                    ];
                });

            $filesystem = new Filesystem(new LocalFilesystemAdapter($this->tempRoot));
            $poller = new SftpInboundReceiver(
                $receiver,
                app(InboundPayloadResolver::class),
                app(InboundConnectionLogger::class),
            );

            $processed = $poller->poll($connection, $filesystem);

            $this->assertSame(2, $processed);
            $this->assertFalse($filesystem->fileExists('inbox/dup.xml'));
            $this->assertFalse($filesystem->fileExists('inbox/ok.xml'));
            $this->assertTrue($this->processedExists($filesystem, 'dup.xml'));
            $this->assertTrue($this->processedExists($filesystem, 'ok.xml'));
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
            Mockery::close();
        }
    }

    #[Test]
    public function poll_sets_last_error_when_listing_fails(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'SFTP poll failure',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Sftp,
                'is_active' => true,
                'settings' => [
                    'inbound_path' => 'inbox',
                    'processed_path' => 'processed',
                ],
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $filesystem = Mockery::mock(Filesystem::class);
            $filesystem->shouldReceive('listContents')
                ->once()
                ->andThrow(new \RuntimeException('SFTP list failed'));

            $poller = new SftpInboundReceiver(
                Mockery::mock(InboundEpcisReceiver::class),
                app(InboundPayloadResolver::class),
                app(InboundConnectionLogger::class),
            );

            $processed = $poller->poll($connection, $filesystem);

            $this->assertSame(0, $processed);
            $connection->refresh();
            $this->assertSame('SFTP list failed', $connection->last_error);
            $this->assertNotNull($connection->last_polled_at);
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
            Mockery::close();
        }
    }

    private function processedExists(Filesystem $filesystem, string $filename): bool
    {
        foreach ($filesystem->listContents('processed', false) as $item) {
            if (str_ends_with($item->path(), $filename)) {
                return true;
            }
        }

        return false;
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
}
