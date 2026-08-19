<?php

namespace App\Services\Integrations;

use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\InboundConnection;
use App\Support\SftpConnectionProviderFactory;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\StorageAttributes;

class SftpInboundReceiver
{
    public function __construct(
        private readonly InboundEpcisReceiver $receiver,
        private readonly InboundPayloadResolver $payloadResolver,
        private readonly InboundConnectionLogger $logger,
    ) {}

    public function poll(InboundConnection $connection, ?Filesystem $filesystem = null): int
    {
        $settings = $connection->settings ?? [];
        $inboundPath = trim($settings['inbound_path'] ?? '/', '/');
        $processedPath = trim($settings['processed_path'] ?? 'processed', '/');

        $filesystem ??= $this->filesystemFor($connection);
        $processed = 0;

        try {
            $listing = $filesystem->listContents($inboundPath, false);

            foreach ($listing as $item) {
                if ($item->type() !== StorageAttributes::TYPE_FILE) {
                    continue;
                }

                $path = $item->path();
                $filename = basename($path);

                if (! str_ends_with(strtolower($filename), '.xml')) {
                    continue;
                }

                if ($this->wasProcessed($connection, $filename)) {
                    continue;
                }

                $content = $filesystem->read($path);
                $resolved = $this->payloadResolver->resolve($content, null, $filename);

                try {
                    $this->receiver->receive(
                        connection: $connection,
                        content: $resolved['content'],
                        originalFilename: $resolved['originalName'] ?? $filename,
                        receivedVia: 'sftp_poll',
                        metadata: [
                            'remote_path' => $path,
                        ],
                    );
                } catch (DuplicateEpcisUploadException $e) {
                    // Mirror HTTPS webhook: acknowledge duplicate and continue the inbox.
                    $this->logger->log(
                        $connection,
                        'receive',
                        'success',
                        'Duplicate EPCIS upload skipped during SFTP poll.',
                        [
                            'filename' => $filename,
                            'remote_path' => $path,
                            'document_id' => $e->existing->getKey(),
                            'duplicate' => true,
                        ],
                    );
                }

                $destination = $processedPath.'/'.$filename;

                if ($filesystem->fileExists($destination)) {
                    $destination = $processedPath.'/'.now()->format('YmdHis').'_'.$filename;
                }

                $filesystem->move($path, $destination);
                $this->markProcessed($connection, $filename);
                $processed++;
            }

            $this->logger->log(
                $connection,
                'poll',
                'success',
                $processed === 0
                    ? 'Poll completed with no new files.'
                    : "Poll completed — {$processed} file(s) processed.",
                ['files_processed' => $processed],
            );

            $connection->update(['last_error' => null]);
        } catch (\Throwable $exception) {
            $connection->update([
                'last_polled_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);

            $this->logger->log(
                $connection,
                'poll',
                'failed',
                $exception->getMessage(),
            );
        }

        return $processed;
    }

    private function filesystemFor(InboundConnection $connection): Filesystem
    {
        $settings = $connection->settings ?? [];
        $provider = SftpConnectionProviderFactory::forInboundConnection($connection);
        $root = $settings['root'] ?? '/';

        return new Filesystem(new SftpAdapter($provider, $root));
    }

    private function wasProcessed(InboundConnection $connection, string $filename): bool
    {
        $processed = $connection->settings['processed_files'] ?? [];

        return in_array($filename, $processed, true);
    }

    private function markProcessed(InboundConnection $connection, string $filename): void
    {
        $settings = $connection->settings ?? [];
        $processed = $settings['processed_files'] ?? [];
        $processed[] = $filename;
        $settings['processed_files'] = array_values(array_unique(array_slice($processed, -500)));

        $connection->update(['settings' => $settings]);
    }
}
