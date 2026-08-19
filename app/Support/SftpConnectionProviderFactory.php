<?php

namespace App\Support;

use App\Models\InboundConnection;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

class SftpConnectionProviderFactory
{
    public static function forInboundConnection(InboundConnection $connection): SftpConnectionProvider
    {
        $credentials = $connection->credentials ?? [];
        $settings = $connection->settings ?? [];

        return new SftpConnectionProvider(
            host: $credentials['host'] ?? $settings['host'] ?? '',
            username: $credentials['username'] ?? '',
            password: $credentials['password'] ?? null,
            privateKey: $credentials['private_key'] ?? null,
            passphrase: $credentials['passphrase'] ?? null,
            port: (int) ($settings['port'] ?? 22),
            timeout: (int) ($settings['timeout'] ?? 30),
        );
    }
}
