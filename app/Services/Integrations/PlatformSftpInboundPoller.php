<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Enums\EpcisReceivedVia;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Services\Epcis\Hub\EpcisHubRouter;
use App\Support\Integrations\PlatformSftpConfig;
use App\Support\SftpConnectionProviderFactory;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use Throwable;

/**
 * Polls the platform-owned SFTP drop per environment and hub-routes each
 * inbound EPCIS file to the owning tenant connection (approved connections
 * only, via EpcisHubRouter).
 */
class PlatformSftpInboundPoller
{
    public function __construct(
        private readonly PlatformSftpConfig $config,
        private readonly EpcisHubRouter $hubRouter,
        private readonly InboundEpcisReceiver $receiver,
        private readonly InboundPayloadResolver $payloadResolver,
    ) {}

    public function poll(string $environment, ?Filesystem $filesystem = null): int
    {
        $inboundPath = trim($this->config->inboundPath($environment), '/');
        $processedPath = trim($this->config->processedPath($environment), '/');
        $failedPath = 'failed';

        $filesystem ??= $this->filesystemFor($environment);
        $processed = 0;

        try {
            $listing = $filesystem->listContents($inboundPath, false);
        } catch (Throwable $exception) {
            Log::error('Platform SFTP poll failed to list the inbound path.', [
                'environment' => $environment,
                'inbound_path' => $inboundPath,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }

        foreach ($listing as $item) {
            if ($item->type() !== StorageAttributes::TYPE_FILE) {
                continue;
            }

            $path = $item->path();
            $filename = basename($path);

            if (! str_ends_with(strtolower($filename), '.xml')) {
                continue;
            }

            try {
                $content = $filesystem->read($path);
                $resolved = $this->payloadResolver->resolve($content, null, $filename);
                $xml = $resolved['content'];
            } catch (Throwable $exception) {
                Log::error('Platform SFTP poll could not read a file; leaving it for the next poll.', [
                    'environment' => $environment,
                    'remote_path' => $path,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            try {
                $resolution = $this->hubRouter->resolve('tracepharma', $xml, $environment);
            } catch (RuntimeException $exception) {
                // Permanent routing failure (unknown receiver, ambiguous sender,
                // unapproved connection) — quarantine instead of retrying forever.
                $this->move($filesystem, $path, $failedPath.'/'.$filename);

                Log::warning('Platform SFTP file could not be routed to a tenant; moved to failed/.', [
                    'environment' => $environment,
                    'remote_path' => $path,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($resolution->isProbe()) {
                $this->move($filesystem, $path, $processedPath.'/'.$filename);

                continue;
            }

            $tenant = $resolution->tenant;
            $connection = $resolution->connection;

            try {
                TenantAccess::assertActive($tenant);
                TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

                TenantRunner::run($tenant, function () use ($connection, $xml, $resolved, $filename, $path, $environment): void {
                    $this->receiver->receive(
                        connection: $connection,
                        content: $xml,
                        originalFilename: $resolved['originalName'] ?? $filename,
                        receivedVia: EpcisReceivedVia::SftpHubPoll->value,
                        metadata: [
                            'remote_path' => $path,
                            'environment' => $environment,
                        ],
                    );
                });
            } catch (DuplicateEpcisUploadException) {
                // Acknowledge duplicates like the tenant poller does.
            } catch (Throwable $exception) {
                // Transient processing errors stay in place for the next poll.
                Log::error('Platform SFTP file failed tenant processing; leaving it for the next poll.', [
                    'environment' => $environment,
                    'remote_path' => $path,
                    'tenant_id' => $tenant->getKey(),
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $this->move($filesystem, $path, $processedPath.'/'.$filename);
            $processed++;
        }

        return $processed;
    }

    private function filesystemFor(string $environment): Filesystem
    {
        return new Filesystem(new SftpAdapter(
            SftpConnectionProviderFactory::forPlatformEdge($this->config, $environment),
            '/',
        ));
    }

    private function move(Filesystem $filesystem, string $source, string $destination): void
    {
        if ($filesystem->fileExists($destination)) {
            $destination = dirname($destination).'/'.now()->format('YmdHis').'_'.basename($destination);
        }

        $filesystem->move($source, $destination);
    }
}
