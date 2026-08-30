<?php

namespace App\Support;

use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Support\Epcis\EpcisSubscriptionUrl;
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
     * Deny loopback / link-local / cloud metadata before connect.
     * RFC1918 remains allowed for on-prem SFTP (same posture as WMS / printers).
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSafeHost(string $host): void
    {
        $host = EpcisSubscriptionUrl::unwrapIpv4MappedAddress(trim($host));

        if ($host === '') {
            throw new \InvalidArgumentException('SFTP host is required.');
        }

        $lower = strtolower($host);
        if (
            $lower === 'localhost'
            || str_ends_with($lower, '.localhost')
            || $lower === 'metadata.google.internal'
            || $lower === 'metadata.goog'
            || str_ends_with($lower, '.metadata.google.internal')
        ) {
            throw new \InvalidArgumentException('SFTP host must not target a loopback or metadata host.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : TenantSettings::resolveWmsHostAddresses($host);

        foreach ($addresses as $address) {
            if (TenantSettings::isDeniedWmsResolvedAddress($address)) {
                throw new \InvalidArgumentException(
                    'SFTP host must not target a loopback, link-local, or metadata address.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    private static function makeProvider(array $credentials, array $settings): SftpConnectionProvider
    {
        $host = trim((string) ($credentials['host'] ?? $settings['host'] ?? ''));
        self::assertSafeHost($host);

        return new SftpConnectionProvider(
            host: $host,
            username: $credentials['username'] ?? '',
            password: $credentials['password'] ?? null,
            privateKey: $credentials['private_key'] ?? null,
            passphrase: $credentials['passphrase'] ?? null,
            port: (int) ($settings['port'] ?? 22),
            timeout: (int) ($settings['timeout'] ?? 30),
        );
    }
}
