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
        $dir = trim((string) ($settings['outbound_path'] ?? 'outbound/epcis'), '/');
        $path = ($dir === '' ? '' : $dir.'/').ltrim($filename, '/');

        $filesystem->write($path, $content);
    }

    private function filesystemFor(OutboundConnection $connection): Filesystem
    {
        $settings = $connection->settings ?? [];
        $provider = SftpConnectionProviderFactory::forOutboundConnection($connection);
        $root = $settings['root'] ?? '/';

        return new Filesystem(new SftpAdapter($provider, $root));
    }
}
