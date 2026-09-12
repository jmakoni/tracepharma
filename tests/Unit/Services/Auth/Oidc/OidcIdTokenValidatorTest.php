<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth\Oidc;

use App\Services\Auth\Oidc\OidcIdTokenValidator;
use PHPUnit\Framework\Attributes\Test;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\TestCase;

final class OidcIdTokenValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_matching_nonce_in_id_token(): void
    {
        $nonce = 'expected-nonce-value';
        $user = (new SocialiteUser)->map([
            'id' => 'sub-1',
            'email' => 'user@acme.test',
        ]);
        $user->setAccessTokenResponseBody([
            'id_token' => $this->fakeIdToken(['nonce' => $nonce]),
        ]);

        app(OidcIdTokenValidator::class)->assertNonceMatches($user, $nonce);

        $this->assertTrue(true);
    }

    #[Test]
    public function it_rejects_mismatched_nonce_in_id_token(): void
    {
        $user = (new SocialiteUser)->map([
            'id' => 'sub-1',
            'email' => 'user@acme.test',
        ]);
        $user->setAccessTokenResponseBody([
            'id_token' => $this->fakeIdToken(['nonce' => 'other-nonce']),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC nonce validation failed.');

        app(OidcIdTokenValidator::class)->assertNonceMatches($user, 'expected-nonce');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function fakeIdToken(array $claims): string
    {
        $encode = static function (array $payload): string {
            return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        };

        return $encode(['alg' => 'none']).'.'.$encode($claims).'.signature';
    }
}
