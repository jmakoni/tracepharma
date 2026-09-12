<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Support\Integrations\Concerns\ManagesPlatformEdgeSettings;

/**
 * Platform-owned SFTP drop per hub environment. The platform polls the
 * inbound path for EPCIS files and hub-routes them to tenant connections;
 * outbound sends can fall back to these credentials for platform egress.
 */
class PlatformSftpConfig
{
    use ManagesPlatformEdgeSettings;

    /** @var list<string> */
    private const STRING_KEYS = [
        'host',
        'port',
        'username',
        'password',
        'private_key',
        'passphrase',
        'host_fingerprint',
        'inbound_path',
        'processed_path',
        'outbound_path',
    ];

    public function host(string $environment): ?string
    {
        return $this->value($environment, 'host');
    }

    public function port(string $environment): int
    {
        $port = $this->value($environment, 'port');

        return $port !== null && (int) $port > 0 ? (int) $port : 22;
    }

    public function username(string $environment): ?string
    {
        return $this->value($environment, 'username');
    }

    public function password(string $environment): ?string
    {
        return $this->value($environment, 'password');
    }

    public function privateKey(string $environment): ?string
    {
        return $this->value($environment, 'private_key');
    }

    public function passphrase(string $environment): ?string
    {
        return $this->value($environment, 'passphrase');
    }

    public function hostFingerprint(string $environment): ?string
    {
        return $this->value($environment, 'host_fingerprint');
    }

    public function inboundPath(string $environment): string
    {
        return $this->value($environment, 'inbound_path') ?? '/';
    }

    public function processedPath(string $environment): string
    {
        return $this->value($environment, 'processed_path') ?? 'processed';
    }

    public function outboundPath(string $environment): string
    {
        return $this->value($environment, 'outbound_path') ?? 'outbound';
    }

    public function isConfigured(string $environment): bool
    {
        return $this->host($environment) !== null
            && $this->username($environment) !== null
            && $this->hostFingerprint($environment) !== null
            && ($this->password($environment) !== null || $this->privateKey($environment) !== null);
    }

    /**
     * Present + filled values are stored, present + empty clears, absent keys
     * are left untouched (secret fields are write-only in the UI).
     *
     * @param  array<string, string|null>  $values
     */
    public function save(string $environment, array $values): void
    {
        $this->saveValues($environment, $values, self::STRING_KEYS);
    }

    protected function prefix(): string
    {
        return 'platform_sftp';
    }
}
