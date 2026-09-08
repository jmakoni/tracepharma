<?php

namespace App\Support;

use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\Integrations\PlatformSftpConfig;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

class SftpConnectionProviderFactory
{
    public static function forInboundConnection(InboundConnection $connection): SftpConnectionProvider
    {
        $credentials = $connection->credentials ?? [];
        $settings = $connection->settings ?? [];

        return self::makeProvider($credentials, $settings);
    }

    public static function forPlatformEdge(PlatformSftpConfig $config, string $environment): SftpConnectionProvider
    {
        return self::makeProvider(
            [
                'host' => $config->host($environment),
                'username' => $config->username($environment),
                'password' => $config->password($environment),
                'private_key' => $config->privateKey($environment),
                'passphrase' => $config->passphrase($environment),
            ],
            [
                'port' => $config->port($environment),
                'host_fingerprint' => $config->hostFingerprint($environment),
            ],
        );
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
     * Unresolvable hostnames fail closed so Flysystem cannot re-resolve to a denied address.
     *
     * @return list<string> Safe resolved addresses (use for connect pinning).
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSafeHost(string $host): array
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

        if ($addresses === []) {
            throw new \InvalidArgumentException('SFTP host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (TenantSettings::isDeniedWmsResolvedAddress($address)) {
                throw new \InvalidArgumentException(
                    'SFTP host must not target a loopback, link-local, or metadata address.',
                );
            }
        }

        return array_values($addresses);
    }

    /**
     * Resolve SSH host key fingerprint from connection settings/credentials.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    public static function resolveHostFingerprint(array $credentials, array $settings): ?string
    {
        foreach ([
            $settings['host_fingerprint'] ?? null,
            $settings['hostFingerprint'] ?? null,
            $credentials['host_fingerprint'] ?? null,
            $credentials['hostFingerprint'] ?? null,
        ] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $fingerprint = trim($candidate);
            if ($fingerprint !== '') {
                return $fingerprint;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    private static function makeProvider(array $credentials, array $settings): SftpConnectionProvider
    {
        $host = trim((string) ($credentials['host'] ?? $settings['host'] ?? ''));
        $addresses = self::assertSafeHost($host);
        // Pin to a resolved safe address so phpseclib cannot re-lookup a flipped A/AAAA.
        $connectHost = self::fsockopenHost($addresses[0]);

        $hostFingerprint = self::resolveHostFingerprint($credentials, $settings);
        if ($hostFingerprint === null) {
            throw new \InvalidArgumentException(
                'SFTP host_fingerprint is required so the SSH server key can be verified.',
            );
        }

        return new SftpConnectionProvider(
            host: $connectHost,
            username: $credentials['username'] ?? '',
            password: $credentials['password'] ?? null,
            privateKey: $credentials['private_key'] ?? null,
            passphrase: $credentials['passphrase'] ?? null,
            port: (int) ($settings['port'] ?? 22),
            timeout: (int) ($settings['timeout'] ?? 30),
            hostFingerprint: $hostFingerprint,
        );
    }

    /**
     * Format a vetted IP for phpseclib fsockopen (bracket IPv6).
     */
    public static function fsockopenHost(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$address.']';
        }

        return $address;
    }
}
