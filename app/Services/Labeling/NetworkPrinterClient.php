<?php

namespace App\Services\Labeling;

use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\TenantSettings;

class NetworkPrinterClient
{
    public function send(string $host, int $port, string $payload, int $timeoutSeconds = 5): void
    {
        $addresses = self::assertSafePrinterHost($host);
        // Pin to a resolved safe address so DNS cannot rebind to metadata/loopback after the check.
        $connectHost = self::fsockopenHost($addresses[0]);

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($connectHost, $port, $errno, $errstr, $timeoutSeconds);

        if ($socket === false) {
            throw new \RuntimeException("Unable to connect to printer at {$host}:{$port} — {$errstr} ({$errno})");
        }

        try {
            $written = fwrite($socket, $payload);

            if ($written === false || $written < strlen($payload)) {
                throw new \RuntimeException("Incomplete write to printer at {$host}:{$port}.");
            }
        } finally {
            fclose($socket);
        }
    }

    /**
     * Deny loopback / link-local / cloud metadata before fsockopen.
     * RFC1918 remains allowed for on-prem warehouse printers (same posture as WMS).
     * Unresolvable hostnames fail closed.
     *
     * @return non-empty-list<string> Safe resolved addresses (use for connect pinning).
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSafePrinterHost(string $host): array
    {
        $host = EpcisSubscriptionUrl::unwrapIpv4MappedAddress(trim($host));

        if ($host === '') {
            throw new \InvalidArgumentException('Printer host is required.');
        }

        $lower = strtolower($host);
        if (
            $lower === 'localhost'
            || str_ends_with($lower, '.localhost')
            || $lower === 'metadata.google.internal'
            || $lower === 'metadata.goog'
            || str_ends_with($lower, '.metadata.google.internal')
        ) {
            throw new \InvalidArgumentException('Printer host must not target a loopback or metadata host.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : TenantSettings::resolveWmsHostAddresses($host);

        if ($addresses === []) {
            throw new \InvalidArgumentException('Printer host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (TenantSettings::isDeniedWmsResolvedAddress($address)) {
                throw new \InvalidArgumentException(
                    'Printer host must not target a loopback, link-local, or metadata address.',
                );
            }
        }

        return array_values($addresses);
    }

    /**
     * Format a vetted IP for fsockopen (bracket IPv6).
     */
    public static function fsockopenHost(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$address.']';
        }

        return $address;
    }
}
