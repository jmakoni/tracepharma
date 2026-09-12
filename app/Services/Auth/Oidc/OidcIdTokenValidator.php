<?php

declare(strict_types=1);

namespace App\Services\Auth\Oidc;

use Laravel\Socialite\Contracts\User as SocialiteUser;

final class OidcIdTokenValidator
{
    public function assertNonceMatches(SocialiteUser $socialiteUser, string $expectedNonce): void
    {
        $idToken = $this->extractIdToken($socialiteUser);

        if ($idToken === null) {
            throw new \RuntimeException('OIDC id_token missing from provider response.');
        }

        $payload = $this->decodePayload($idToken);
        $nonce = $payload['nonce'] ?? null;

        if (! is_string($nonce) || ! hash_equals($expectedNonce, $nonce)) {
            throw new \RuntimeException('OIDC nonce validation failed.');
        }
    }

    private function extractIdToken(SocialiteUser $socialiteUser): ?string
    {
        if (property_exists($socialiteUser, 'accessTokenResponseBody')
            && is_array($socialiteUser->accessTokenResponseBody ?? null)) {
            $token = $socialiteUser->accessTokenResponseBody['id_token'] ?? null;

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        if (property_exists($socialiteUser, 'id_token')
            && is_string($socialiteUser->id_token)
            && $socialiteUser->id_token !== '') {
            return $socialiteUser->id_token;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new \RuntimeException('OIDC id_token is malformed.');
        }

        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payloadJson === false) {
            throw new \RuntimeException('OIDC id_token payload is invalid.');
        }

        $payload = json_decode($payloadJson, true);

        if (! is_array($payload)) {
            throw new \RuntimeException('OIDC id_token payload is invalid.');
        }

        return $payload;
    }
}
