<?php

namespace App\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\EpcisHub\PlatformOutboundEgress;
use App\Support\Integrations\PlatformSftpConfig;
use App\Support\SftpConnectionProviderFactory;
use DomainException;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;

class SftpOutboundSender
{
    public function __construct(
        private readonly PlatformOutboundEgress $egress,
        private readonly PlatformSftpConfig $platformSftp,
        private readonly EpcisHubPlatformConfig $platformConfig,
    ) {}

    public function send(
        OutboundConnection $connection,
        string $content,
        string $filename,
        ?Filesystem $filesystem = null,
    ): void {
        $settings = $connection->settings ?? [];

        // Hub-linked connections without their own SFTP credentials drop files
        // through the platform SFTP edge.
        if ($filesystem === null && $this->egress->usesPlatformSftp($connection)) {
            $environment = $this->platformConfig->currentEnvironment();

            if ($this->platformSftp->isConfigured($environment)) {
                // Platform edge path is owned by PlatformSftpConfig — never trust
                // tenant settings.outbound_path on the shared drop FS.
                $dir = $this->normalizedOutboundPath(
                    $this->platformSftp->outboundPath($environment),
                );
                $inbound = trim($this->platformSftp->inboundPath($environment), '/');
                if ($dir === $inbound) {
                    throw new DomainException(
                        'Platform SFTP outbound_path must not equal inbound_path.',
                    );
                }

                $this->writeFile($this->platformFilesystem($environment), $dir, $filename, $content);

                return;
            }
        }

        $credentials = $connection->credentials ?? [];
        $host = trim((string) ($credentials['host'] ?? $settings['host'] ?? ''));
        if ($host === '') {
            throw new DomainException('SFTP outbound connection is missing host.');
        }

        $username = trim((string) ($credentials['username'] ?? ''));
        if ($username === '') {
            throw new DomainException('SFTP outbound connection is missing username.');
        }

        $dir = $this->normalizedOutboundPath((string) ($settings['outbound_path'] ?? 'outbound/epcis'));
        $filesystem ??= $this->filesystemFor($connection);
        $this->writeFile($filesystem, $dir, $filename, $content);
    }

    private function writeFile(Filesystem $filesystem, string $dir, string $filename, string $content): void
    {
        $path = ($dir === '' ? '' : $dir.'/').ltrim($filename, '/');

        $filesystem->write($path, $content);
    }

    /**
     * Relative path under the SFTP adapter root only — reject traversal and absolute paths.
     */
    private function normalizedOutboundPath(string $outboundPath): string
    {
        $normalized = str_replace('\\', '/', $outboundPath);

        // Leading "/" is stripped below (relative to adapter root). Reject Windows / UNC absolutes.
        if (str_starts_with($normalized, '//') || preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
            throw new DomainException('SFTP outbound_path must be a relative path.');
        }

        if (str_contains($normalized, '..')) {
            throw new DomainException('SFTP outbound_path must not contain parent-directory segments.');
        }

        $dir = trim($normalized, '/');

        foreach ($dir === '' ? [] : explode('/', $dir) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new DomainException('SFTP outbound_path must not contain parent-directory segments.');
            }
        }

        return $dir;
    }

    private function filesystemFor(OutboundConnection $connection): Filesystem
    {
        $settings = $connection->settings ?? [];
        $provider = SftpConnectionProviderFactory::forOutboundConnection($connection);
        $root = $settings['root'] ?? '/';

        return new Filesystem(new SftpAdapter($provider, $root));
    }

    protected function platformFilesystem(string $environment): Filesystem
    {
        return new Filesystem(new SftpAdapter(
            SftpConnectionProviderFactory::forPlatformEdge($this->platformSftp, $environment),
            '/',
        ));
    }
}
