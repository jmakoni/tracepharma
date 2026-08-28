<?php

namespace App\Support;

use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

class SftpConnectionProviderFactory
{
    public static function forInboundConnection(InboundConnection $connection): SftpConnectionProvider
    {
        $credentials = $connection->credentials ?? [];
        $settings = $connection->settings ?? [];

        return self::makeProvider($credentials, $settings);
    }

    public static function forOutboundConnection(OutboundConnection $connection): SftpConnectionProvider
    {
        $credentials = $connection->credentials ?? [];
        $settings = $connection->settings ?? [];

        return self::makeProvider($credentials, $settings);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    private static function makeProvider(array $credentials, array $settings): SftpConnectionProvider
    {
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
