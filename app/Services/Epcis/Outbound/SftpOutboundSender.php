<?php

namespace App\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Support\SftpConnectionProviderFactory;
use DomainException;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;

class SftpOutboundSender
{
    public function send(
        OutboundConnection $connection,
        string $content,
        string $filename,
        ?Filesystem $filesystem = null,
    ): void {
        $settings = $connection->settings ?? [];
        $credentials = $connection->credentials ?? [];
        $host = trim((string) ($credentials['host'] ?? $settings['host'] ?? ''));
        if ($host === '') {
            throw new DomainException('SFTP outbound connection is missing host.');
        }

        $username = trim((string) ($credentials['username'] ?? ''));
        if ($username === '') {
            throw new DomainException('SFTP outbound connection is missing username.');
        }

        $filesystem ??= $this->filesystemFor($connection);
        $dir = $this->normalizedOutboundPath((string) ($settings['outbound_path'] ?? 'outbound/epcis'));
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
}
