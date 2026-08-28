<?php

declare(strict_types=1);

namespace App\Support\Epcis;

/**
 * SSRF guards for EPCIS subscription webhook target URLs.
 * HTTPS only; deny loopback / link-local / metadata / private ranges.
 */
final class EpcisSubscriptionUrl
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function assertSafeTargetUrl(?string $url): void
    {
        if ($url === null || trim($url) === '') {
            throw new \InvalidArgumentException('Subscription target URL is required.');
        }

        $parsed = parse_url($url);
        if ($parsed === false || ! is_array($parsed)) {
            throw new \InvalidArgumentException('Subscription target URL is not valid.');
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException(
                'Subscription target URL must not include credentials. Use the subscription secret for HMAC signing.',
            );
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('Subscription target URL must use HTTPS.');
        }

        $host = self::unwrapIpv4MappedAddress((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('Subscription target URL is not valid.');
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === 'metadata.google.internal'
            || $host === 'metadata.goog') {
            throw new \InvalidArgumentException('Subscription target URL must not target a private or metadata host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                throw new \InvalidArgumentException('Subscription target URL must not target a private or metadata host.');
            }
        }
    }

    /**
     * Resolve hostnames at delivery time and deny loopback / link-local / metadata.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSafeAtConnect(string $url): void
    {
        self::assertSafeTargetUrl($url);

        $host = self::unwrapIpv4MappedAddress((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('Subscription target URL is not valid.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : self::resolveHostAddresses($host);

        foreach ($addresses as $address) {
            if (self::isDeniedResolvedAddress($address)) {
                throw new \InvalidArgumentException('Subscription target URL must not target a private or metadata host.');
            }
        }
    }

    public static function unwrapIpv4MappedAddress(string $host): string
    {
        $host = strtolower(trim($host, '[]'));
        if (str_starts_with($host, '::ffff:')) {
            $mapped = substr($host, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $mapped;
            }
        }

        return $host;
    }

    public static function isDeniedResolvedAddress(string $ip): bool
    {
        $ip = self::unwrapIpv4MappedAddress($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = array_map('intval', explode('.', $ip));
            if (($octets[0] ?? null) === 127) {
                return true;
            }
            if (($octets[0] ?? null) === 169 && ($octets[1] ?? null) === 254) {
                return true;
            }
            // Deny RFC1918 + CGNAT for subscription webhooks (stricter than WMS on-prem allow).
            if (($octets[0] ?? null) === 10) {
                return true;
            }
            if (($octets[0] ?? null) === 172 && ($octets[1] ?? 0) >= 16 && ($octets[1] ?? 0) <= 31) {
                return true;
            }
            if (($octets[0] ?? null) === 192 && ($octets[1] ?? null) === 168) {
                return true;
            }
            if (($octets[0] ?? null) === 100 && ($octets[1] ?? 0) >= 64 && ($octets[1] ?? 0) <= 127) {
                return true;
            }

            return false;
        }

        // IPv6: deny loopback / link-local / ULA
        if ($ip === '::1' || str_starts_with($ip, 'fe80:') || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @return list<string>
     */
    private static function resolveHostAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $ipv4 = gethostbyname($host);
            if ($ipv4 === $host || filter_var($ipv4, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('Subscription target URL host could not be resolved.');
            }

            return [$ipv4];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        if ($addresses === []) {
            throw new \InvalidArgumentException('Subscription target URL host could not be resolved.');
        }

        return array_values(array_unique($addresses));
    }
}
