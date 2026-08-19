<?php

namespace App\Filament\App\Concerns;

trait TransformsConnectionCredentials
{
    /**
     * @return list<string>
     */
    protected function reservedCredentialKeys(): array
    {
        return [
            'username',
            'password',
            'private_key',
            'passphrase',
            'host',
            'signing_cert_pem',
            'signing_key_pem',
            'partner_encrypt_cert_pem',
            'as2_mdn_webhook_secret',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillDedicatedCredentialFields(array $data, ?array $credentials = null): array
    {
        $credentials ??= [];

        $data['sftp_username'] = $credentials['username'] ?? null;
        $data['sftp_password'] = $credentials['password'] ?? null;
        $data['sftp_private_key'] = $credentials['private_key'] ?? null;
        $data['sftp_passphrase'] = $credentials['passphrase'] ?? null;
        $data['as2_signing_cert_pem'] = $credentials['signing_cert_pem'] ?? null;
        $data['as2_signing_key_pem'] = $credentials['signing_key_pem'] ?? null;
        $data['as2_partner_encrypt_cert_pem'] = $credentials['partner_encrypt_cert_pem'] ?? null;
        $data['as2_mdn_webhook_secret'] = $credentials['as2_mdn_webhook_secret'] ?? null;

        $reserved = $this->reservedCredentialKeys();

        $data['credential_pairs'] = collect($credentials)
            ->reject(fn (mixed $value, string $key): bool => in_array($key, $reserved, true))
            ->map(fn (mixed $value, string $key): array => ['key' => $key, 'value' => $value])
            ->values()
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    protected function mergeDedicatedCredentialFields(array $data, ?array $existing = null): array
    {
        $credentials = array_merge($existing ?? [], $data['credentials'] ?? []);

        foreach ([
            'sftp_username' => 'username',
            'sftp_password' => 'password',
            'sftp_private_key' => 'private_key',
            'sftp_passphrase' => 'passphrase',
            'as2_signing_cert_pem' => 'signing_cert_pem',
            'as2_signing_key_pem' => 'signing_key_pem',
            'as2_partner_encrypt_cert_pem' => 'partner_encrypt_cert_pem',
            'as2_mdn_webhook_secret' => 'as2_mdn_webhook_secret',
        ] as $field => $credentialKey) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if (filled($value)) {
                $credentials[$credentialKey] = $value;
            } elseif ($existing !== null && isset($existing[$credentialKey])) {
                $credentials[$credentialKey] = $existing[$credentialKey];
            }

            unset($data[$field]);
        }

        $data['credentials'] = $credentials;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function transformInboundCredentialPairs(array $data, ?array $existing = null): array
    {
        if ($existing === null) {
            $data['credentials'] = collect($data['credential_pairs'] ?? [])
                ->filter(fn (array $pair): bool => filled($pair['key'] ?? null))
                ->mapWithKeys(fn (array $pair): array => [$pair['key'] => $pair['value'] ?? ''])
                ->all();

            unset($data['credential_pairs']);

            return $this->mergeDedicatedCredentialFields($data);
        }

        $incoming = collect($data['credential_pairs'] ?? [])
            ->filter(fn (array $pair): bool => filled($pair['key'] ?? null))
            ->mapWithKeys(function (array $pair) use ($existing): array {
                $key = $pair['key'];
                $value = $pair['value'] ?? '';

                if ($value === '' && isset($existing[$key])) {
                    return [$key => $existing[$key]];
                }

                return [$key => $value];
            })
            ->all();

        $data['credentials'] = array_merge($existing, $incoming);
        unset($data['credential_pairs']);

        return $this->mergeDedicatedCredentialFields($data, $existing);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function transformOutboundCredentialPairs(array $data, ?array $existing = null): array
    {
        if ($existing === null) {
            $data['credentials'] = collect($data['credential_pairs'] ?? [])
                ->filter(fn (array $pair): bool => filled($pair['key'] ?? null))
                ->mapWithKeys(fn (array $pair): array => [$pair['key'] => $pair['value'] ?? ''])
                ->all();

            unset($data['credential_pairs']);

            return $this->mergeDedicatedCredentialFields($data);
        }

        $incoming = collect($data['credential_pairs'] ?? [])
            ->filter(fn (array $pair): bool => filled($pair['key'] ?? null))
            ->mapWithKeys(function (array $pair) use ($existing): array {
                $key = $pair['key'];
                $value = $pair['value'] ?? '';

                if ($value === '' && isset($existing[$key])) {
                    return [$key => $existing[$key]];
                }

                return [$key => $value];
            })
            ->all();

        $data['credentials'] = array_merge($existing, $incoming);
        unset($data['credential_pairs']);

        return $this->mergeDedicatedCredentialFields($data, $existing);
    }
}
