<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\Concerns\ManagesPlatformEdgeSettings;
use App\Support\PlatformSettings;
use Throwable;

/**
 * Platform-owned AS2 station identity per hub environment. Partners send AS2
 * messages to the platform edge; the platform decrypts with the station key,
 * verifies the signature against the sender registry, then hub-routes the
 * EPCIS payload to the owning tenant connection.
 */
class PlatformAs2Station
{
    use ManagesPlatformEdgeSettings;

    /** @var list<string> */
    private const STRING_KEYS = [
        'station_id',
        'signing_cert_pem',
        'signing_key_pem',
        'decrypt_cert_pem',
        'decrypt_key_pem',
    ];

    public function __construct(
        private readonly EpcisHubPlatformConfig $hubConfig,
    ) {}

    public function stationId(string $environment): ?string
    {
        return $this->value($environment, 'station_id');
    }

    public function signingCertPem(string $environment): ?string
    {
        return $this->value($environment, 'signing_cert_pem');
    }

    public function signingKeyPem(string $environment): ?string
    {
        return $this->value($environment, 'signing_key_pem');
    }

    public function decryptCertPem(string $environment): ?string
    {
        return $this->value($environment, 'decrypt_cert_pem');
    }

    public function decryptKeyPem(string $environment): ?string
    {
        return $this->value($environment, 'decrypt_key_pem');
    }

    /**
     * @return list<array{label: ?string, as2_id: string, signing_cert_pem: string}>
     */
    public function senders(string $environment): array
    {
        $raw = PlatformSettings::get($this->settingsKey($environment, 'senders'));

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $senders = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $as2Id = trim((string) ($entry['as2_id'] ?? ''));
            $cert = trim((string) ($entry['signing_cert_pem'] ?? ''));

            if ($as2Id === '' || $cert === '') {
                continue;
            }

            $label = $entry['label'] ?? null;
            $senders[] = [
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
                'as2_id' => $as2Id,
                'signing_cert_pem' => $cert,
            ];
        }

        return $senders;
    }

    /**
     * Signing certificate registered for an AS2-From identifier, if any.
     */
    public function senderCertificate(string $environment, string $as2Id): ?string
    {
        $as2Id = trim($as2Id);

        if ($as2Id === '') {
            return null;
        }

        foreach ($this->senders($environment) as $sender) {
            if ($sender['as2_id'] === $as2Id) {
                return $sender['signing_cert_pem'];
            }
        }

        return null;
    }

    public function isConfigured(string $environment): bool
    {
        return $this->stationId($environment) !== null
            && $this->decryptCertPem($environment) !== null
            && $this->decryptKeyPem($environment) !== null;
    }

    /**
     * Public certificate partners encrypt to and verify our signatures with.
     */
    public function publicCertificatePem(string $environment): ?string
    {
        return $this->decryptCertPem($environment) ?? $this->signingCertPem($environment);
    }

    /**
     * Public URL partners send AS2 messages to for this environment.
     */
    public function as2Url(string $environment): string
    {
        return 'https://'.$this->hubConfig->host($environment).'/api/webhooks/as2/hub';
    }

    /**
     * Present + filled values are stored, present + empty clears, absent keys
     * are left untouched (PEM fields are write-only in the UI).
     *
     * @param  array<string, string|null>  $values
     */
    public function save(string $environment, array $values): void
    {
        $this->saveValues($environment, $values, self::STRING_KEYS);
    }

    /**
     * @param  list<array{label?: ?string, as2_id?: ?string, signing_cert_pem?: ?string}>  $senders
     */
    public function setSenders(string $environment, array $senders): void
    {
        $normalized = [];

        foreach ($senders as $entry) {
            $as2Id = trim((string) ($entry['as2_id'] ?? ''));
            $cert = trim((string) ($entry['signing_cert_pem'] ?? ''));

            if ($as2Id === '' || $cert === '') {
                continue;
            }

            $label = $entry['label'] ?? null;
            $normalized[] = [
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
                'as2_id' => $as2Id,
                'signing_cert_pem' => $cert,
            ];
        }

        $key = $this->settingsKey($environment, 'senders');

        if ($normalized === []) {
            PlatformSettings::forget($key);

            return;
        }

        PlatformSettings::put($key, json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    protected function prefix(): string
    {
        return 'platform_as2';
    }
}
