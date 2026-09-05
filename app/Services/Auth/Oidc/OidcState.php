<?php

declare(strict_types=1);

namespace App\Services\Auth\Oidc;

use Illuminate\Support\Str;

final class OidcState
{
    public const SESSION_KEY = 'oidc.login_context';

    /**
     * @param  array{plane: string, tenant_id: ?string, provider: string, nonce: string}  $payload
     */
    public function put(array $payload): void
    {
        session([self::SESSION_KEY => $payload]);
    }

    /**
     * @return array{plane: string, tenant_id: ?string, provider: string, nonce: string}
     */
    public function pull(): array
    {
        $payload = session()->pull(self::SESSION_KEY);

        if (! is_array($payload)
            || ! is_string($payload['plane'] ?? null)
            || ! is_string($payload['provider'] ?? null)
            || ! is_string($payload['nonce'] ?? null)
        ) {
            throw new \InvalidArgumentException('Missing or invalid OIDC login context.');
        }

        return [
            'plane' => $payload['plane'],
            'tenant_id' => isset($payload['tenant_id']) && is_string($payload['tenant_id']) ? $payload['tenant_id'] : null,
            'provider' => $payload['provider'],
            'nonce' => $payload['nonce'],
        ];
    }

    public function freshNonce(): string
    {
        return Str::random(40);
    }
}
